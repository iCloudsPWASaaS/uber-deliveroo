<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Models\Store;
use App\Services\UberEatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class WebhookController
{
    public function __construct(protected UberEatsService $uber) {}

    public function uberEats(Request $request)
    {
        $rawBody = $request->getContent();
        $signature = $request->header('x-uber-signature') ?? $request->header('x-uber-webhook-signature') ?? '';
        $secret = config('services.uber_eats.webhook_secret');

        if ($secret) {
            $expected = hash_hmac('sha256', $rawBody, $secret);
            if (strtolower($signature) !== strtolower($expected)) {
                return response()->json(['error' => 'Invalid signature'], 401);
            }
        }

        $payload = json_decode($rawBody, true) ?: [];
        $eventType = $payload['event_type'] ?? null;

        switch ($eventType) {
            case 'orders.notification':
            case 'orders.release':
                $orderUuid = $payload['meta']['order_uuid'] ?? null;
                if (! $orderUuid) {
                    break;
                }

                $existing = Order::where('platformInfo.platform', 'uber_eats')
                    ->where('platformInfo.platformOrderId', $orderUuid)
                    ->first();
                if ($existing) {
                    break;
                }

                $orderDetails = $this->uber->request('GET', "/eats/order/{$orderUuid}");
                $storeId = $payload['meta']['store_id'] ?? ($orderDetails['store_id'] ?? null);

                $store = Store::where('platforms.platform', 'uber_eats')
                    ->where('platforms.storeId', $storeId)
                    ->first();

                if (! $store) {
                    break;
                }

                $this->createUberOrder($store, $orderUuid, $storeId, $orderDetails);
                break;

            default:
                // Unhandled event type - log but don't fail
                break;
        }

        return response()->json(['received' => true]);
    }

    public function deliveroo(Request $request)
    {
        $rawBody = $request->getContent();
        $secret = config('services.deliveroo.webhook_secret');

        if ($secret) {
            $guid = (string) ($request->header('x-deliveroo-sequence-guid') ?? '');
            $expected = strtolower((string) ($request->header('x-deliveroo-hmac-sha256') ?? ''));

            if (! $this->verifyDeliverooSignature($rawBody, $guid, $expected, $secret)) {
                return response()->json(['error' => 'Invalid signature'], 401);
            }
        }

        $payload = json_decode($rawBody, true) ?: [];
        $event = $payload['event'] ?? $payload['type'] ?? $payload['event_type'] ?? null;
        $data = $payload['data'] ?? $payload['payload'] ?? $payload;
        $siteId = $payload['site_id'] ?? ($data['site_id'] ?? null);

        $store = $siteId
            ? Store::where('platforms.platform', 'deliveroo')->where('platforms.storeId', $siteId)->first()
            : null;

        if (! $store) {
            return response()->json(['received' => true]);
        }

        $orderId = isset($data['order_id']) ? (string) $data['order_id'] : (isset($data['id']) ? (string) $data['id'] : null);

        switch ($event) {
            case 'order.create':
            case 'order.created':
                if ($orderId) {
                    $this->createDeliverooOrder($store, $orderId, $siteId, $data, $payload);
                }
                break;

            case 'order.accept':
            case 'order.accepted':
                $this->updateDeliverooStatus($orderId, 'accepted');
                break;

            case 'order.reject':
            case 'order.rejected':
            case 'order.cancel':
            case 'order.cancelled':
                $this->updateDeliverooStatus($orderId, 'cancelled', $data['reason'] ?? 'Cancelled by Deliveroo');
                break;

            case 'rider.assigned':
            case 'order.ready_for_pickup':
            case 'order.picked_up':
            case 'order.delivered':
                $map = [
                    'rider.assigned' => 'preparing',
                    'order.ready_for_pickup' => 'ready',
                    'order.picked_up' => 'picked_up',
                    'order.delivered' => 'delivered',
                ];
                $this->updateDeliverooStatus($orderId, $map[$event] ?? null);
                break;

            default:
                $map = [
                    'started_preparing' => 'preparing',
                    'food_ready' => 'ready',
                    'picked_up' => 'picked_up',
                    'delivered' => 'delivered',
                ];
                if ($orderId && isset($map[$event])) {
                    $this->updateDeliverooStatus($orderId, $map[$event]);
                }
        }

        return response()->json(['received' => true]);
    }

    protected function createUberOrder(Store $store, string $orderUuid, ?string $platformStoreId, array $details): void
    {
        $itemCharge = $details['payment']['payment_detail']['item_charges']['price_breakdown']['total']['gross'] ?? 0;
        $deliveryCharge = $details['payment']['payment_detail']['delivery_charges']['total']['gross'] ?? 0;
        $serviceCharge = $details['payment']['payment_detail']['service_charges']['total']['gross'] ?? 0;

        $items = collect($details['cart']['items'] ?? [])->map(function ($item) {
            $price = $item['payment_detail']['item_charges']['price_breakdown']['total']['gross'] ?? 0;
            $modifiers = collect($item['extra_4'] ?? [])->flatMap(function ($grp) {
                return collect($grp['items'] ?? [])->map(fn ($i) => $i['eat_toolkit_title'] ?? $i['title'] ?? null)->filter()->all();
            })->values()->all();

            return [
                'name' => $item['title'] ?? $item['name'] ?? '',
                'quantity' => $item['quantity'] ?? 1,
                'unitPrice' => $price,
                'totalPrice' => ($item['quantity'] ?? 1) * $price,
                'modifiers' => $modifiers,
                'specialInstructions' => $item['extra_instructions'] ?? null,
            ];
        })->all();

        Order::create([
            'storeId' => (string) $store->_id,
            'userId' => $store->userId,
            'orderNumber' => 'UE-'.strtoupper(substr(str_replace('-', '', (string) $orderUuid), 0, 8)),
            'platform' => 'uber_eats',
            'platformInfo' => [
                'platform' => 'uber_eats',
                'platformOrderId' => (string) $orderUuid,
                'platformStoreId' => (string) $platformStoreId,
                'rawPayload' => $details,
            ],
            'customer' => [
                'name' => $details['customer']['name'] ?? 'Uber Eats Customer',
                'phone' => $details['customer']['phone'] ?? null,
            ],
            'items' => $items,
            'subtotal' => $itemCharge,
            'deliveryFee' => $deliveryCharge,
            'serviceFee' => $serviceCharge,
            'total' => $itemCharge + $deliveryCharge + $serviceCharge,
            'currency' => 'GBP',
            'status' => 'received',
            'statusHistory' => [['status' => 'received', 'timestamp' => now(), 'note' => null]],
            'specialInstructions' => $details['special_instructions'] ?? null,
            'scheduledDeliveryTime' => isset($details['delivery_piece']['time_schedule']['window'])
                ? Carbon::parse($details['delivery_piece']['time_schedule']['window'])
                : null,
        ]);
    }

    protected function createDeliverooOrder(Store $store, string $orderId, ?string $siteId, array $data, array $payload): void
    {
        $existing = Order::where('platformInfo.platform', 'deliveroo')
            ->where('platformInfo.platformOrderId', $orderId)
            ->first();
        if ($existing) {
            return;
        }

        $items = collect($data['order_items'] ?? $data['items'] ?? [])->map(function ($item) {
            $unit = $item['unit_price'] ?? $item['price'] ?? 0;
            $qty = $item['quantity'] ?? 0;

            return [
                'name' => $item['name'] ?? $item['title'] ?? '',
                'quantity' => $qty,
                'unitPrice' => $unit,
                'totalPrice' => $item['total_price'] ?? ($qty * $unit),
                'modifiers' => collect($item['modifiers'] ?? [])->map(fn ($m) => $m['name'] ?? $m)->all(),
                'specialInstructions' => $item['special_instructions'] ?? null,
            ];
        })->all();

        $totals = $data['totals'] ?? $data['pricing'] ?? [];
        $subtotal = $totals['subtotal'] ?? $totals['cart_total'] ?? 0;
        $delivery = $totals['delivery_fee'] ?? 0;
        $service = $totals['service_charge'] ?? $totals['service_fee'] ?? 0;

        Order::create([
            'storeId' => (string) $store->_id,
            'userId' => $store->userId,
            'orderNumber' => 'DR-'.strtoupper(substr(str_replace('-', '', $orderId), 0, 8)),
            'platform' => 'deliveroo',
            'platformInfo' => [
                'platform' => 'deliveroo',
                'platformOrderId' => $orderId,
                'platformStoreId' => (string) $siteId,
                'rawPayload' => $payload,
            ],
            'customer' => [
                'name' => $data['customer']['name'] ?? 'Deliveroo Customer',
                'phone' => $data['customer']['phone'] ?? null,
            ],
            'items' => $items,
            'subtotal' => $subtotal,
            'deliveryFee' => $delivery,
            'serviceFee' => $service,
            'total' => $totals['total'] ?? ($subtotal + $delivery + $service),
            'currency' => $data['currency'] ?? 'GBP',
            'status' => 'received',
            'statusHistory' => [['status' => 'received', 'timestamp' => now(), 'note' => null]],
            'specialInstructions' => $data['special_instructions'] ?? null,
        ]);
    }

    protected function updateDeliverooStatus(?string $orderId, ?string $status, ?string $note = null): void
    {
        if (! $orderId || ! $status) {
            return;
        }

        $order = Order::where('platformInfo.platform', 'deliveroo')
            ->where('platformInfo.platformOrderId', $orderId)
            ->first();

        if ($order) {
            $order->recordStatus($status, $note);
            $order->save();
        }
    }

    protected function verifyDeliverooSignature(string $body, string $guid, string $expected, string $secret): bool
    {
        foreach ([' ', " \n "] as $separator) {
            $computed = strtolower(hash_hmac('sha256', $guid.$separator.$body, $secret));
            if ($computed !== '' && hash_equals($computed, $expected)) {
                return true;
            }
        }

        return false;
    }
}
