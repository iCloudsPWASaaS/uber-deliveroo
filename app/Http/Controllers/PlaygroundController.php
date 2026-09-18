<?php

namespace App\Http\Controllers;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Store;
use App\Services\DeliverooService;
use App\Services\UberEatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlaygroundController extends Controller
{
    public function __construct(
        protected UberEatsService $uber,
        protected DeliverooService $deliveroo,
    ) {}

    protected function resolveStore(Request $request): ?Store
    {
        $storeId = $request->input('store_id') ?? $request->query('store');

        return $storeId ? Store::find($storeId) : null;
    }

    public function home()
    {
        return view('playground.home', [
            'storeCount' => Store::count(),
            'orderCount' => Order::count(),
            'itemCount' => MenuItem::count(),
            'stores' => Store::orderByDesc('createdAt')->limit(5)->get(),
            'recentOrders' => Order::orderByDesc('createdAt')->limit(5)->get(),
        ]);
    }

    public function stores(Request $request)
    {
        $stores = Store::orderByDesc('createdAt')->get();
        $selected = $this->resolveStore($request);

        return view('playground.stores', [
            'stores' => $stores,
            'selected' => $selected,
            'items' => $selected ? MenuItem::where('storeId', (string) $selected->_id)->get() : collect(),
            'orders' => $selected ? Order::where('storeId', (string) $selected->_id)->orderByDesc('createdAt')->limit(20)->get() : collect(),
        ]);
    }

    public function createStore(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'address' => ['required', 'string'],
            'city' => ['required', 'string'],
            'postcode' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
        ]);

        $store = Store::create([
            'userId' => null,
            'name' => $data['name'],
            'address' => $data['address'],
            'city' => $data['city'],
            'postcode' => $data['postcode'] ?? '',
            'phone' => $data['phone'] ?? '',
            'email' => $data['email'] ?? '',
            'cuisine' => [],
            'platforms' => [
                ['platform' => 'uber_eats', 'isConnected' => false],
                ['platform' => 'deliveroo', 'isConnected' => false],
            ],
            'operatingHours' => [],
            'isOnline' => true,
            'isBusy' => false,
            'isPaused' => false,
            'timezone' => 'Europe/London',
            'averagePrepTime' => 15,
            'maxOrdersPerHour' => 30,
        ]);

        return redirect()->route('stores', ['store' => (string) $store->_id])->with('status', 'Store created: '.$store->name)->with('ok', true);
    }

    protected function orderedStore(Request $request): ?Store
    {
        return $this->resolveStore($request);
    }

    /*
    |--------------------------------------------------------------------------
    | Uber Eats playground
    |--------------------------------------------------------------------------
    */

    public function uber(Request $request)
    {
        $store = $this->orderedStore($request);

        return view('playground.uber', [
            'stores' => Store::orderBy('name')->get(),
            'store' => $store,
            'menuItems' => $store ? MenuItem::where('storeId', (string) $store->_id)->get() : collect(),
            'orders' => $store ? Order::where('storeId', (string) $store->_id)->where('platform', 'uber_eats')->orderByDesc('createdAt')->limit(20)->get() : collect(),
        ]);
    }

    public function uberConnect(Request $request)
    {
        $store = $this->orderedStore($request);
        if (! $store) {
            return back()->with('status', 'Select a store first')->with('ok', false);
        }

        $platforms = $store->platforms ?? [];
        $index = collect($platforms)->search(fn ($p) => ($p['platform'] ?? null) === 'uber_eats');
        $config = [
            'platform' => 'uber_eats',
            'isConnected' => true,
            'storeId' => $request->input('store_ref', ''),
            'clientId' => $request->input('client_id', ''),
            'clientSecret' => $request->input('client_secret', ''),
            'apiKey' => $request->input('client_id', ''),
            'apiSecret' => $request->input('client_secret', ''),
            'webhookSecret' => $request->input('webhook_secret') ?: Str::random(48),
        ];

        if ($index === false) {
            $platforms[] = $config;
        } else {
            $platforms[$index] = array_merge($platforms[$index], $config);
        }
        $store->platforms = array_values($platforms);
        $store->save();

        return redirect()->route('uber', ['store' => (string) $store->_id])
            ->with('status', 'Uber Eats connected. Webhook secret: '.$config['webhookSecret'])
            ->with('ok', true)
            ->with('payload', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function uberFetchStores(Request $request)
    {
        $result = $this->uber->fetchStores([
            'clientId' => $request->input('client_id'),
            'clientSecret' => $request->input('client_secret'),
        ]);

        return back()->with('status', 'Fetch Uber stores: '.($result['message'] ?? ''))
            ->with('ok', $result['success'] ?? false)
            ->with('payload', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function uberStatus(Request $request)
    {
        $store = $this->orderedStore($request);
        if (! $store) {
            return back()->with('status', 'Select a store first')->with('ok', false);
        }

        $result = $this->uber->setStoreStatus($store, [
            'is_online' => $request->boolean('is_online'),
            'busy_mode' => $request->boolean('busy_mode'),
            'pause_new_orders' => $request->boolean('pause_new_orders'),
        ]);

        return redirect()->route('uber', ['store' => (string) $store->_id])
            ->with('status', 'Uber status: '.($result['message'] ?? ''))
            ->with('ok', $result['success'] ?? false)
            ->with('payload', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function uberMenuPull(Request $request)
    {
        $store = $this->orderedStore($request);
        if (! $store) {
            return back()->with('status', 'Select a store first')->with('ok', false);
        }

        $result = $this->uber->getMenu($store);

        return redirect()->route('uber', ['store' => (string) $store->_id])
            ->with('status', 'Pull Uber menu: '.($result['message'] ?? ''))
            ->with('ok', $result['success'] ?? false)
            ->with('payload', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function uberMenuPush(Request $request)
    {
        $store = $this->orderedStore($request);
        if (! $store) {
            return back()->with('status', 'Select a store first')->with('ok', false);
        }

        $items = MenuItem::where('storeId', (string) $store->_id)->where('isAvailable', true)->get();
        $menu = [
            'categories' => $items->groupBy('category')->map(fn ($group, $category) => [
                'title' => $category,
                'items' => $group->values()->map(fn ($i) => [
                    'title' => $i->name,
                    'description' => $i->description,
                    'price' => ['amount' => $i->basePrice, 'currency' => $i->currency ?? 'GBP'],
                    'item_id' => (string) $i->_id,
                    'available' => $i->isAvailable,
                ])->all(),
            ])->values()->all(),
        ];

        $result = $this->uber->pushMenu($store, $menu);

        return redirect()->route('uber', ['store' => (string) $store->_id])
            ->with('status', 'Push Uber menu: '.($result['message'] ?? ''))
            ->with('ok', $result['success'] ?? false)
            ->with('payload', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function uberOrder(Request $request)
    {
        $store = $this->orderedStore($request);
        $orderId = $request->input('order_id');

        if (! $store || ! $orderId) {
            return back()->with('status', 'Select a store and provide an order ID')->with('ok', false);
        }

        $action = $request->input('action');
        $reason = $request->input('reason');

        $result = match ($action) {
            'accept' => $this->uber->acceptOrder($orderId),
            'deny' => $this->uber->denyOrder($orderId, $reason),
            'cancel' => $this->uber->cancelOrder($orderId, $reason ? ['info' => $reason] : null),
            'preparing' => $this->uber->setOrderStatus($orderId, 'preparing'),
            'ready' => $this->uber->setOrderStatus($orderId, 'ready'),
            default => ['success' => false, 'message' => 'Invalid action'],
        };

        $order = Order::where('storeId', (string) $store->_id)->where('_id', $orderId)->first();
        if ($order && ($result['success'] ?? false)) {
            $status = match ($action) {
                'accept' => 'accepted',
                'deny', 'cancel' => 'cancelled',
                'preparing' => 'preparing',
                'ready' => 'ready',
                default => null,
            };
            if ($status) {
                $order->recordStatus($status, in_array($action, ['deny', 'cancel'], true) ? $reason : null);
                $order->save();
            }
        }

        return redirect()->route('uber', ['store' => (string) $store->_id])
            ->with('status', 'Uber order action: '.($result['message'] ?? ''))
            ->with('ok', $result['success'] ?? false)
            ->with('payload', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /*
    |--------------------------------------------------------------------------
    | Deliveroo playground
    |--------------------------------------------------------------------------
    */

    public function deliveroo(Request $request)
    {
        $store = $this->orderedStore($request);

        return view('playground.deliveroo', [
            'stores' => Store::orderBy('name')->get(),
            'store' => $store,
            'menuItems' => $store ? MenuItem::where('storeId', (string) $store->_id)->get() : collect(),
            'orders' => $store ? Order::where('storeId', (string) $store->_id)->where('platform', 'deliveroo')->orderByDesc('createdAt')->limit(20)->get() : collect(),
        ]);
    }

    public function deliverooConnect(Request $request)
    {
        $store = $this->orderedStore($request);
        if (! $store) {
            return back()->with('status', 'Select a store first')->with('ok', false);
        }

        $platforms = $store->platforms ?? [];
        $index = collect($platforms)->search(fn ($p) => ($p['platform'] ?? null) === 'deliveroo');
        $config = [
            'platform' => 'deliveroo',
            'isConnected' => true,
            'storeId' => $request->input('site_ref', ''),
            'brandId' => $request->input('brand_id', ''),
            'clientId' => $request->input('client_id', ''),
            'clientSecret' => $request->input('client_secret', ''),
            'apiKey' => $request->input('client_id', ''),
            'apiSecret' => $request->input('client_secret', ''),
            'webhookSecret' => $request->input('webhook_secret') ?: Str::random(48),
        ];

        if ($index === false) {
            $platforms[] = $config;
        } else {
            $platforms[$index] = array_merge($platforms[$index], $config);
        }
        $store->platforms = array_values($platforms);
        $store->save();

        return redirect()->route('deliveroo', ['store' => (string) $store->_id])
            ->with('status', 'Deliveroo connected. Webhook secret: '.$config['webhookSecret'])
            ->with('ok', true)
            ->with('payload', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function deliverooFetchSites(Request $request)
    {
        $result = $this->deliveroo->fetchSites([
            'clientId' => $request->input('client_id'),
            'clientSecret' => $request->input('client_secret'),
            'brandId' => $request->input('brand_id'),
        ]);

        return back()->with('status', 'Fetch Deliveroo sites: '.($result['message'] ?? ''))
            ->with('ok', $result['success'] ?? false)
            ->with('payload', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function deliverooStatus(Request $request)
    {
        $store = $this->orderedStore($request);
        if (! $store) {
            return back()->with('status', 'Select a store first')->with('ok', false);
        }

        $result = $this->deliveroo->setSiteStatus($store, [
            'is_online' => $request->boolean('is_online'),
            'busy_mode' => $request->boolean('busy_mode'),
            'pause_new_orders' => $request->boolean('pause_new_orders'),
        ]);

        return redirect()->route('deliveroo', ['store' => (string) $store->_id])
            ->with('status', 'Deliveroo status: '.($result['message'] ?? ''))
            ->with('ok', $result['success'] ?? false)
            ->with('payload', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function deliverooMenuPull(Request $request)
    {
        $store = $this->orderedStore($request);
        if (! $store) {
            return back()->with('status', 'Select a store first')->with('ok', false);
        }

        $result = $this->deliveroo->getMenu($store);

        return redirect()->route('deliveroo', ['store' => (string) $store->_id])
            ->with('status', 'Pull Deliveroo menu: '.($result['message'] ?? ''))
            ->with('ok', $result['success'] ?? false)
            ->with('payload', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function deliverooMenuPush(Request $request)
    {
        $store = $this->orderedStore($request);
        if (! $store) {
            return back()->with('status', 'Select a store first')->with('ok', false);
        }

        $items = MenuItem::where('storeId', (string) $store->_id)->where('isAvailable', true)->get();
        $menu = [
            'items' => $items->map(fn ($i) => [
                'name' => $i->name,
                'description' => $i->description,
                'price' => $i->basePrice,
                'pos_id' => (string) $i->_id,
                'available' => $i->isAvailable,
                'category' => $i->category,
            ])->all(),
        ];

        $result = $this->deliveroo->pushMenu($store, $menu);

        return redirect()->route('deliveroo', ['store' => (string) $store->_id])
            ->with('status', 'Push Deliveroo menu: '.($result['message'] ?? ''))
            ->with('ok', $result['success'] ?? false)
            ->with('payload', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function deliverooOrder(Request $request)
    {
        $store = $this->orderedStore($request);
        $orderId = $request->input('order_id');

        if (! $store || ! $orderId) {
            return back()->with('status', 'Select a store and provide an order ID')->with('ok', false);
        }

        $action = $request->input('action');

        $result = match ($action) {
            'accept' => $this->deliveroo->acceptOrder($store, $orderId),
            'reject' => $this->deliveroo->rejectOrder($store, $orderId),
            'preparing' => $this->deliveroo->setOrderStatus($store, $orderId, 'preparing'),
            'ready' => $this->deliveroo->setOrderStatus($store, $orderId, 'ready'),
            default => ['success' => false, 'message' => 'Invalid action'],
        };

        $order = Order::where('storeId', (string) $store->_id)->where('_id', $orderId)->first();
        if ($order && ($result['success'] ?? false)) {
            $status = match ($action) {
                'accept' => 'accepted',
                'reject' => 'cancelled',
                'preparing' => 'preparing',
                'ready' => 'ready',
                default => null,
            };
            if ($status) {
                $order->recordStatus($status, $action === 'reject' ? 'Rejected by merchant' : null);
                $order->save();
            }
        }

        return redirect()->route('deliveroo', ['store' => (string) $store->_id])
            ->with('status', 'Deliveroo order action: '.($result['message'] ?? ''))
            ->with('ok', $result['success'] ?? false)
            ->with('payload', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
