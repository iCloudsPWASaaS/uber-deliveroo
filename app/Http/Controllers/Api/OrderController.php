<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Models\Store;
use App\Services\DeliverooService;
use App\Services\UberEatsService;
use Illuminate\Http\Request;

class OrderController
{
    public function __construct(
        protected UberEatsService $uber,
        protected DeliverooService $deliveroo,
    ) {}

    public function index(string $storeId)
    {
        $store = Store::find($storeId);
        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $orders = Order::where('storeId', $storeId)
            ->orderByDesc('createdAt')
            ->limit(100)
            ->get();

        return response()->json(['orders' => $orders]);
    }

    public function show(string $storeId, string $orderId)
    {
        $store = Store::find($storeId);
        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $order = Order::where('storeId', $storeId)->where('_id', $orderId)->first();
        if (! $order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json(['order' => $order]);
    }

    public function action(Request $request, string $storeId, string $orderId)
    {
        $store = Store::find($storeId);
        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $order = Order::where('storeId', $storeId)->where('_id', $orderId)->first();
        if (! $order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        $action = $request->input('action');
        $reason = $request->input('reason');
        $platformOrderId = $order->platformInfo['platformOrderId'] ?? null;

        if (! $platformOrderId) {
            return response()->json(['error' => 'Order is missing a platform order ID'], 400);
        }

        $isUber = ($order->platform ?? null) === 'uber_eats';

        $result = match ($action) {
            'accept' => $isUber
                ? $this->uber->acceptOrder($platformOrderId)
                : $this->deliveroo->acceptOrder($store, $platformOrderId),
            'reject' => $isUber
                ? $this->uber->denyOrder($platformOrderId, $reason)
                : $this->deliveroo->rejectOrder($store, $platformOrderId),
            'preparing' => $isUber
                ? $this->uber->setOrderStatus($platformOrderId, 'preparing')
                : $this->deliveroo->setOrderStatus($store, $platformOrderId, 'preparing'),
            'ready' => $isUber
                ? $this->uber->setOrderStatus($platformOrderId, 'ready')
                : $this->deliveroo->setOrderStatus($store, $platformOrderId, 'ready'),
            'cancel' => $this->uber->cancelOrder($platformOrderId, $reason ? ['info' => $reason] : null),
            default => null,
        };

        if ($result === null) {
            return response()->json(['error' => 'Invalid action'], 400);
        }

        if ($result['success'] ?? false) {
            $status = match ($action) {
                'accept' => 'accepted',
                'reject', 'cancel' => 'cancelled',
                'preparing' => 'preparing',
                'ready' => 'ready',
                default => null,
            };

            if ($status) {
                $note = in_array($action, ['reject', 'cancel'], true)
                    ? ($reason ?: ($action === 'reject' ? 'Rejected by merchant' : null))
                    : null;
                $order->recordStatus($status, $note);
                $order->save();
            }
        }

        return response()->json(['result' => $result]);
    }
}
