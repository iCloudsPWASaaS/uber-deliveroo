<?php

namespace App\Http\Controllers;

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Store;
use App\Services\DeliverooService;
use App\Services\UberEatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

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

    private function uberCallbackUri(): string
    {
        return config('services.uber_eats.redirect_uri') ?: rtrim(config('app.url'), '/').'/uber/oauth/callback';
    }

    public function uberActivate(Request $request)
    {
        $store = $this->orderedStore($request);
        if (! $store) {
            return back()->with('status', 'Select a store first')->with('ok', false);
        }

        $config = config('services.uber_eats');
        $authorizeUrl = rtrim(str_replace('/oauth/v2/token', '/oauth/v2/authorize', $config['token_url']), '/');

        return redirect()->away($authorizeUrl.'?'.http_build_query([
            'client_id' => $config['client_id'],
            'redirect_uri' => $this->uberCallbackUri(),
            'scope' => 'eats.pos_provisioning',
            'response_type' => 'code',
            'state' => (string) $store->_id,
        ]));
    }

    public function uberOAuthCallback(Request $request)
    {
        $error = $request->query('error');
        if ($error) {
            $description = $request->query('error_description', '');

            return redirect()->route('uber')
                ->with('status', 'Uber activation failed: '.$error.($description ? ' - '.$description : ''))
                ->with('ok', false);
        }

        $code = $request->query('code');
        if (! $code) {
            return redirect()->route('uber')
                ->with('status', 'Uber activation failed: missing authorization code')
                ->with('ok', false);
        }

        $store = Store::find($request->query('state'));

        try {
            $auth = $this->uber->exchangeAuthorizationCode($code, $this->uberCallbackUri());
        } catch (Throwable $e) {
            return redirect()->route('uber', $store ? ['store' => (string) $store->_id] : [])
                ->with('status', 'Uber activation token exchange failed: '.$this->uber->errorMessage($e))
                ->with('ok', false);
        }

        try {
            $res = $this->uber->request('GET', '/eats/stores', $auth['access_token']);
        } catch (Throwable $e) {
            return redirect()->route('uber', $store ? ['store' => (string) $store->_id] : [])
                ->with('status', 'Uber merchant authorized, but store discovery failed: '.$this->uber->errorMessage($e))
                ->with('ok', false)
                ->with('payload', json_encode($auth, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $stores = collect($res['stores'] ?? []);
        $storeId = $stores->first()['store_id'] ?? null;

        $activated = 0;
        $activationError = null;

        foreach ($stores as $s) {
            try {
                $this->uber->activateStore($s['store_id'], $auth['access_token']);
                $activated++;
            } catch (Throwable $e) {
                $activationError = $this->uber->errorMessage($e);
            }
        }

        if ($store && $storeId) {
            $platforms = $store->platforms ?? [];
            $index = collect($platforms)->search(fn ($p) => ($p['platform'] ?? null) === 'uber_eats');

            if ($index !== false) {
                $platforms[$index] = array_merge($platforms[$index], [
                    'isConnected' => true,
                    'storeId' => $storeId,
                ]);
            } else {
                $platforms[] = [
                    'platform' => 'uber_eats',
                    'isConnected' => true,
                    'storeId' => $storeId,
                ];
            }

            $store->platforms = array_values($platforms);
            $store->save();
        }

        return redirect()->route('uber', $store ? ['store' => (string) $store->_id] : [])
            ->with('status', 'Uber store activated: '.$activated.'/'.$stores->count().' store(s) provisioned'.($storeId ? ' - store ref set to '.$storeId : '').($activationError ? ' - error: '.$activationError : ''))
            ->with('ok', $activationError ? false : true)
            ->with('payload', json_encode(['stores' => $stores->all()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

        if (! ($result['success'] ?? false)) {
            return redirect()->route('uber', ['store' => (string) $store->_id])
                ->with('status', 'Pull Uber menu: '.($result['message'] ?? ''))
                ->with('ok', false)
                ->with('payload', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $data = $result['data'] ?? [];
        $itemsById = collect($data['items'] ?? [])->keyBy('id');
        $categoriesById = collect($data['categories'] ?? [])->keyBy('id');

        $created = 0;
        $updated = 0;
        $categoryTitles = [];

        foreach ($data['menus'] ?? [] as $menu) {
            foreach ($menu['category_ids'] ?? [] as $catId) {
                $category = $categoriesById[$catId] ?? null;
                if (! $category) {
                    continue;
                }

                $catTitle = $category['title']['translations'] ?? [];
                $categoryTitle = is_array($catTitle) && $catTitle ? (reset($catTitle) ?: 'General') : 'General';
                $categoryTitles[$catId] = $categoryTitles[$catId] ?? $categoryTitle;

                foreach ($category['entities'] ?? [] as $entity) {
                    if (($entity['type'] ?? 'ITEM') !== 'ITEM') {
                        continue;
                    }

                    $item = $itemsById[$entity['id']] ?? null;
                    if (! $item) {
                        continue;
                    }

                    $titleTr = $item['title']['translations'] ?? [];
                    $descTr = $item['description']['translations'] ?? [];
                    $name = is_array($titleTr) && $titleTr ? (reset($titleTr) ?: ('Item '.$entity['id'])) : 'Item '.$entity['id'];
                    $description = is_array($descTr) && $descTr ? reset($descTr) : null;

                    $attrs = [
                        'name' => $name,
                        'description' => $description,
                        'category' => $categoryTitles[$catId],
                        'basePrice' => (int) ($item['price_info']['price'] ?? 0),
                        'currency' => 'GBP',
                        'image' => $item['image_url'] ?? null,
                        'isAvailable' => true,
                        'externalId' => (string) $entity['id'],
                    ];

                    $storeId = (string) $store->_id;
                    $existing = null;
                    $externalData = (string) ($item['external_data'] ?? '');
                    if ($externalData !== '') {
                        $existing = MenuItem::where('storeId', $storeId)->where('_id', $externalData)->first();
                    }
                    if (! $existing) {
                        $existing = MenuItem::where('storeId', $storeId)->where('externalId', $attrs['externalId'])->first();
                    }

                    if ($existing) {
                        $existing->fill($attrs);
                        $existing->save();
                        $updated++;
                    } else {
                        MenuItem::create(array_merge(['storeId' => $storeId], $attrs));
                        $created++;
                    }
                }
            }
        }

        $synced = $created + $updated;

        return redirect()->route('uber', ['store' => (string) $store->_id])
            ->with('status', "Pull Uber menu: {$synced} item(s) synced to local menu ({$created} new, {$updated} updated)")
            ->with('ok', true)
            ->with('payload', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function uberMenuPush(Request $request)
    {
        $store = $this->orderedStore($request);
        if (! $store) {
            return back()->with('status', 'Select a store first')->with('ok', false);
        }

        $items = MenuItem::where('storeId', (string) $store->_id)->where('isAvailable', true)->get();

        if ($items->isEmpty()) {
            return redirect()->route('uber', ['store' => (string) $store->_id])
                ->with('status', 'Push local menu to Uber: no local (available) items to push')
                ->with('ok', false);
        }

        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $serviceAvailability = array_map(fn ($day) => [
            'day_of_week' => $day,
            'time_periods' => [['start_time' => '00:00', 'end_time' => '23:59']],
        ], $days);

        $categories = [];
        $uberItems = [];

        foreach ($items->groupBy('category') as $category => $group) {
            $catId = 'cat_'.Str::slug($category ?: 'General');
            $categories[] = [
                'id' => $catId,
                'title' => ['translations' => ['en_gb' => $category ?: 'General']],
                'entities' => $group->values()->map(fn ($i) => ['id' => (string) ($i->externalId ?: $i->_id), 'type' => 'ITEM'])->all(),
            ];

            foreach ($group as $i) {
                $uberItemId = (string) ($i->externalId ?: $i->_id);
                $item = [
                    'id' => $uberItemId,
                    'title' => ['translations' => ['en_gb' => $i->name]],
                    'description' => $i->description ? ['translations' => ['en_gb' => $i->description]] : null,
                    'external_data' => (string) $i->_id,
                    'price_info' => ['price' => (int) ($i->basePrice ?? 0)],
                ];
                if ($i->image) {
                    $item['image_url'] = $i->image;
                }
                $uberItems[] = $item;
            }
        }

        $menu = [
            'menu_type' => 'MENU_TYPE_FULFILLMENT_DELIVERY',
            'menus' => [[
                'id' => 'menu_'.Str::slug($store->name ?? 'store'),
                'title' => ['translations' => ['en_gb' => $store->name ?: 'Menu']],
                'service_availability' => $serviceAvailability,
                'category_ids' => array_column($categories, 'id'),
            ]],
            'categories' => $categories,
            'items' => $uberItems,
            'modifier_groups' => [],
        ];

        $result = $this->uber->pushMenu($store, $menu);

        return redirect()->route('uber', ['store' => (string) $store->_id])
            ->with('status', 'Push local menu to Uber: '.($result['message'] ?? '').' - '.count($uberItems).' item(s) sent')
            ->with('ok', $result['success'] ?? false)
            ->with('payload', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function uberMenuItemSave(Request $request)
    {
        $store = $this->orderedStore($request);
        if (! $store) {
            return back()->with('status', 'Select a store first')->with('ok', false);
        }

        $name = trim((string) $request->input('name'));
        $category = trim((string) $request->input('category')) ?: 'General';
        $pricePence = (int) round(((float) ($request->input('basePrice') ?? 0)) * 100);

        if ($name === '' || $pricePence < 0) {
            return back()->with('status', 'Menu item name and a valid price are required')->with('ok', false);
        }

        $data = [
            'name' => $name,
            'category' => $category,
            'basePrice' => $pricePence,
            'description' => $request->input('description') ?: null,
            'image' => $request->input('image') ?: null,
            'currency' => $request->input('currency') ?: 'GBP',
            'isAvailable' => $request->boolean('isAvailable', true),
        ];

        $itemId = $request->input('item_id');
        $existing = $itemId
            ? MenuItem::where('storeId', (string) $store->_id)->where('_id', $itemId)->first()
            : null;

        if ($existing) {
            $existing->fill($data);
            $existing->save();
            $status = "Menu item '{$existing->name}' updated";
        } else {
            MenuItem::create(array_merge(['storeId' => (string) $store->_id], $data));
            $status = "Menu item '{$name}' added - reload page to edit it";
        }

        return redirect()->route('uber', ['store' => (string) $store->_id])
            ->with('status', $status)
            ->with('ok', true);
    }

    public function uberMenuItemDelete(Request $request)
    {
        $store = $this->orderedStore($request);
        if (! $store) {
            return back()->with('status', 'Select a store first')->with('ok', false);
        }

        $itemId = $request->input('item_id');
        $deleted = $itemId ? MenuItem::where('storeId', (string) $store->_id)->where('_id', $itemId)->delete() : 0;

        return redirect()->route('uber', ['store' => (string) $store->_id])
            ->with('status', $deleted ? 'Menu item removed' : 'Menu item not found')
            ->with('ok', (bool) $deleted);
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
