<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class DeliverooService
{
    public function apiUrl(): string
    {
        return rtrim(config('services.deliveroo.api_url', 'https://api.developers.deliveroo.com'), '/');
    }

    public function authUrl(): string
    {
        return rtrim(config('services.deliveroo.auth_url', 'https://auth.developers.deliveroo.com'), '/');
    }

    /**
     * OAuth2 client-credentials access token (machine-to-machine flow).
     */
    public function fetchToken(string $clientId, string $clientSecret): array
    {
        $response = Http::asForm()
            ->timeout(20)
            ->post($this->authUrl().'/oauth2/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
            ]);

        $response->throw();

        return $response->json();
    }

    /**
     * Generic authenticated Deliveroo request against a full URL.
     */
    public function request(string $method, string $url, ?string $token = null, mixed $data = null): mixed
    {
        $headers = ['Accept' => 'application/json'];

        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        } else {
            $credentials = base64_encode(config('services.deliveroo.client_id').':'.config('services.deliveroo.client_secret'));
            $headers['Authorization'] = 'Basic '.$credentials;
        }

        $options = [];
        if ($data !== null) {
            $options['json'] = $data;
        }

        $response = Http::withHeaders($headers)
            ->timeout(20)
            ->send(strtoupper($method), $url, $options);

        $response->throw();

        return $response->json();
    }

    public function errorMessage(Throwable $error): string
    {
        if ($error instanceof RequestException && $error->response) {
            $data = $error->response->json();
            $message = $data['error']['message'] ?? $data['error']['code'] ?? $data['message'] ?? null;

            if ($message) {
                return $message.' (HTTP '.$error->response->status().')';
            }

            if ($error->response->status() === 403) {
                return 'Forbidden (HTTP 403) - this client is not authorised for that API. The Sites API shows a warning in the Deliveroo developer portal; enable it to list brands/sites.';
            }

            if ($error->response->status() === 404) {
                return 'Not found (HTTP 404) - check the brand_id and site (location) id configured for this store.';
            }

            $body = $error->response->body();

            return $body ? substr($body, 0, 300) : 'Deliveroo request failed (HTTP '.$error->response->status().')';
        }

        if ($error instanceof ConnectionException) {
            return 'Could not reach Deliveroo: '.$error->getMessage();
        }

        return $error->getMessage() ?: 'Deliveroo request failed';
    }

    /**
     * List brands (no brand_id) or sites (when brand_id is given) for the given client credentials.
     */
    public function fetchSites(array $credentials): array
    {
        try {
            $tokenData = $this->fetchToken($credentials['clientId'] ?? '', $credentials['clientSecret'] ?? '');
            $token = $tokenData['access_token'] ?? null;

            if (! $token) {
                return ['success' => false, 'message' => 'Deliveroo did not return an access token'];
            }

            if (! empty($credentials['brandId'])) {
                $data = $this->request('GET', $this->apiUrl()."/site/v1/brands/{$credentials['brandId']}/sites", $token);
                $rawSites = $data['sites'] ?? (is_array($data) && array_is_list($data) ? $data : []);

                $sites = collect($rawSites)
                    ->filter(fn ($s) => ! empty($s['location_id']) || ! empty($s['id']))
                    ->map(fn ($s) => [
                        'siteId' => $s['location_id'] ?? $s['id'] ?? '',
                        'name' => $s['name'] ?? null,
                        'status' => $s['status'] ?? null,
                        'type' => $s['type'] ?? null,
                        'api_access' => $s['api_access'] ?? null,
                    ])->values()->all();

                return ['success' => true, 'message' => count($sites).' Deliveroo site(s) found for brand '.$credentials['brandId'], 'sites' => $sites];
            }

            $data = $this->request('GET', $this->apiUrl().'/site/v1/brands', $token);
            $raw = $data['brands'] ?? (is_array($data) && array_is_list($data) ? $data : []);

            $brands = collect($raw)
                ->map(fn ($b) => [
                    'brandId' => $b['brand_id'] ?? $b['id'] ?? null,
                    'name' => $b['name'] ?? null,
                ])
                ->filter(fn ($b) => $b['brandId'])
                ->values()->all();

            if (empty($brands)) {
                return ['success' => false, 'message' => 'No brands returned by the Sites API for this client'];
            }

            return [
                'success' => true,
                'message' => count($brands).' brand(s) found - copy a brand_id into the connection form, then fetch sites',
                'brands' => $brands,
                'sites' => collect($brands)
                    ->map(fn ($b) => ['siteId' => $b['brandId'], 'name' => $b['name'].' (brand)', 'status' => null])
                    ->values()->all(),
            ];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    protected function platformConfig(Store $store): ?array
    {
        foreach ($store->platforms ?? [] as $platform) {
            if (($platform['platform'] ?? null) === 'deliveroo') {
                return $platform;
            }
        }

        return null;
    }

    protected function platformIndex(Store $store): int|false
    {
        foreach ($store->platforms ?? [] as $i => $platform) {
            if (($platform['platform'] ?? null) === 'deliveroo') {
                return $i;
            }
        }

        return false;
    }

    /**
     * Legacy path builder, kept for reference. Real calls use prefixed URLs directly.
     */
    public function sitePath(array $config, ?string $sub = null): string
    {
        $brand = $config['brandId'] ?? null;
        $site = $config['storeId'] ?? null;

        if (empty($brand)) {
            throw new \RuntimeException('Deliveroo brand ID is not configured for this store');
        }

        return "brands/{$brand}/sites/{$site}".($sub ? "/{$sub}" : '');
    }

    public function getStoreToken(Store $store): string
    {
        $config = $this->platformConfig($store);

        if (! empty($config['accessToken']) && ! empty($config['tokenExpiry'])) {
            try {
                if (Carbon::parse($config['tokenExpiry'])->isFuture()) {
                    return $config['accessToken'];
                }
            } catch (Throwable) {
                // refresh below
            }
        }

        if (empty($config['clientId']) || empty($config['clientSecret'])) {
            throw new \RuntimeException('Deliveroo client credentials not configured');
        }

        $data = $this->fetchToken($config['clientId'], $config['clientSecret']);
        $token = $data['access_token'] ?? null;

        if (! $token) {
            throw new \RuntimeException('Deliveroo did not return an access token');
        }

        $index = $this->platformIndex($store);
        if ($index !== false) {
            $platforms = $store->platforms;
            $platforms[$index]['accessToken'] = $token;
            $platforms[$index]['tokenExpiry'] = Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 300))->toIso8601String();
            $store->platforms = $platforms;
            $store->save();
        }

        return $token;
    }

    public function getSiteStatus(Store $store): array
    {
        $config = $this->platformConfig($store);
        if (! ($config['isConnected'] ?? false)) {
            return ['success' => false, 'message' => 'Deliveroo is not connected for this store'];
        }

        try {
            $token = $this->getStoreToken($store);
            $data = $this->request('GET', $this->apiUrl()."/site/v1/brands/{$config['brandId']}/sites", $token);

            $site = collect($data['sites'] ?? [])
                ->first(function ($s) use ($config) {
                    return ($s['location_id'] ?? null) === ($config['storeId'] ?? null);
                });

            return ['success' => true, 'message' => 'Site status retrieved', 'data' => $site ?? $data];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    public function setSiteStatus(Store $store, array $payload): array
    {
        $config = $this->platformConfig($store);
        if (! ($config['isConnected'] ?? false)) {
            return ['success' => false, 'message' => 'Deliveroo is not connected for this store'];
        }

        try {
            $token = $this->getStoreToken($store);

            if (isset($payload['status'])) {
                $status = strtoupper((string) $payload['status']);
            } else {
                $isOnline = (bool) ($payload['is_online'] ?? true);
                $paused = (bool) ($payload['pause_new_orders'] ?? false);
                $status = $isOnline && ! $paused ? 'OPEN' : 'CLOSED';
            }

            if (! in_array($status, ['OPEN', 'CLOSED', 'READY_TO_OPEN'], true)) {
                $status = ($payload['is_online'] ?? true) ? 'OPEN' : 'CLOSED';
            }

            $result = $this->request(
                'PUT',
                $this->apiUrl()."/site/v1/brands/{$config['brandId']}/sites/{$config['storeId']}/status",
                $token,
                ['status' => $status]
            );

            return ['success' => true, 'message' => 'Deliveroo site status set to '.$status, 'data' => $result ?? ['status' => $status]];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    public function getMenu(Store $store): array
    {
        $config = $this->platformConfig($store);
        if (! ($config['isConnected'] ?? false)) {
            return ['success' => false, 'message' => 'Deliveroo is not connected for this store'];
        }

        try {
            $token = $this->getStoreToken($store);
            $body = $this->request('GET', $this->apiUrl()."/menu/v2/brands/{$config['brandId']}/sites/{$config['storeId']}/menu", $token);
            $menu = $body['menu'] ?? [];

            $categoryById = collect($menu['categories'] ?? [])
                ->flatMap(fn ($c) => collect($c['item_ids'] ?? [])->mapWithKeys(fn ($id) => [$id => $c['name']['en'] ?? $c['name'] ?? 'General']));

            $items = collect($menu['items'] ?? [])->map(function ($item) use ($categoryById) {
                $id = (string) ($item['id'] ?? '');
                $title = $item['name']['en'] ?? $item['name'] ?? 'Unnamed';

                return [
                    'name' => is_array($title) ? ($title['en'] ?? 'Unnamed') : $title,
                    'description' => is_array($item['description'] ?? null) ? ($item['description']['en'] ?? '') : (string) ($item['description'] ?? ''),
                    'category' => $categoryById[$id] ?? 'General',
                    'price' => $item['price_info']['price'] ?? 0,
                    'currency' => 'GBP',
                    'available' => $item['is_available'] ?? true,
                    'id' => $id,
                    'external_data' => (string) ($item['external_data'] ?? ''),
                ];
            })->all();

            return ['success' => true, 'message' => 'Menu retrieved ('.count($items).' item(s))', 'data' => ['items' => $items], 'raw' => $body];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    public function pushMenu(Store $store, array $menu): array
    {
        $config = $this->platformConfig($store);
        if (! ($config['isConnected'] ?? false)) {
            return ['success' => false, 'message' => 'Deliveroo is not connected for this store'];
        }

        try {
            $token = $this->getStoreToken($store);
            $siteId = $config['storeId'] ?? null;

            $items = collect($menu['items'] ?? []);
            $menuId = $menu['id'] ?? $menu['menu_id'] ?? 'menu_'.Str::slug($store->name ?? 'store');

            $categories = $items->groupBy(fn ($i) => $i['category'] ?? 'General')
                ->map(function ($group, $category) {
                    $catId = 'cat_'.Str::slug($category);

                    return [
                        'id' => $catId,
                        'name' => ['en' => $category ?: 'General'],
                        'description' => ['en' => ''],
                        'item_ids' => $group
                            ->map(fn ($i) => (string) ($i['pos_id'] ?? $i['id'] ?? Str::slug($i['name'] ?? 'item')))
                            ->values()->all(),
                    ];
                })->values()->all();

            $deliverooItems = $items->map(function ($i) {
                $id = (string) ($i['pos_id'] ?? $i['id'] ?? Str::slug($i['name'] ?? 'item'));

                return [
                    'id' => $id,
                    'type' => 'ITEM',
                    'name' => ['en' => $i['name'] ?? 'Unnamed'],
                    'description' => $i['description'] ? ['en' => (string) $i['description']] : ['en' => ''],
                    'operational_name' => Str::slug($i['name'] ?? 'item'),
                    'price_info' => ['price' => (int) ($i['price'] ?? $i['basePrice'] ?? 0), 'overrides' => [], 'fees' => []],
                    'tax_rate' => '20',
                    'plu' => $id,
                    'external_data' => $id,
                    'diets' => [],
                    'classifications' => [],
                    'allergies' => [],
                    'highlights' => [],
                    'barcodes' => [],
                    'contains_alcohol' => false,
                    'modifier_ids' => [],
                    'image' => [],
                ];
            })->values()->all();

            $body = [
                'name' => $menu['name'] ?? ($menuId.' menu'),
                'menu' => [
                    'categories' => $categories,
                    'items' => $deliverooItems,
                    'modifiers' => [],
                ],
                'site_ids' => [$siteId],
            ];

            $result = $this->request('PUT', $this->apiUrl()."/menu/v1/brands/{$config['brandId']}/menus/{$menuId}", $token, $body);

            return ['success' => true, 'message' => 'Menu pushed to Deliveroo ('.count($deliverooItems).' item(s))', 'data' => $result];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    public function getOrders(Store $store): array
    {
        $config = $this->platformConfig($store);
        if (! ($config['isConnected'] ?? false)) {
            return ['success' => false, 'message' => 'Deliveroo is not connected for this store'];
        }

        try {
            $token = $this->getStoreToken($store);
            $data = $this->request('GET', $this->apiUrl()."/order/v2/brand/{$config['brandId']}/restaurant/{$config['storeId']}/orders", $token);

            return ['success' => true, 'message' => 'Orders retrieved ('.count($data['orders'] ?? []).' order(s))', 'data' => $data];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    public function acceptOrder(Store $store, string $orderId): array
    {
        return $this->updateOrderStatus($store, $orderId, 'accepted');
    }

    public function rejectOrder(Store $store, string $orderId, ?string $reason = null): array
    {
        return $this->updateOrderStatus($store, $orderId, 'rejected', $reason ?: 'busy');
    }

    public function setOrderStatus(Store $store, string $orderId, string $status): array
    {
        $map = [
            'accept' => 'accepted',
            'accepted' => 'accepted',
            'reject' => 'rejected',
            'rejected' => 'rejected',
            'confirm' => 'confirmed',
            'confirmed' => 'confirmed',
            'preparing' => 'confirmed',
            'ready' => 'confirmed',
        ];

        return $this->updateOrderStatus($store, $orderId, $map[$status] ?? $status);
    }

    protected function updateOrderStatus(Store $store, string $orderId, string $status, ?string $reason = null): array
    {
        $config = $this->platformConfig($store);
        if (! ($config['isConnected'] ?? false)) {
            return ['success' => false, 'message' => 'Deliveroo is not connected for this store'];
        }

        try {
            $token = $this->getStoreToken($store);
            $body = ['status' => $status];
            if ($status === 'rejected') {
                $body['reject_reason'] = $reason ?: 'busy';
            }

            $result = $this->request('PATCH', $this->apiUrl().'/order/v1/orders/'.$orderId, $token, $body);

            return ['success' => true, 'message' => 'Deliveroo order status set to '.$status, 'data' => $result];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }
}