<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class UberEatsService
{
    public function apiUrl(): string
    {
        return rtrim(config('services.uber_eats.api_url', 'https://test-api.uber.com'), '/').'/v1';
    }

    public function tokenUrl(): string
    {
        return config('services.uber_eats.token_url', 'https://sandbox-login.uber.com/oauth/v2/token');
    }

    /**
     * Uber Eats OAuth 2.0 client credentials flow.
     */
    public function getToken(array $credentials): array
    {
        $response = Http::asForm()->timeout(15)->post($this->tokenUrl(), [
            'grant_type' => 'client_credentials',
            'client_id' => $credentials['clientId'] ?? $credentials['apiKey'] ?? '',
            'client_secret' => $credentials['clientSecret'] ?? $credentials['apiSecret'] ?? '',
            'scope' => 'eats.store',
        ]);

        $response->throw();

        return $response->json();
    }

    /**
     * Authenticated request against the Uber Eats v1 API.
     */
    public function request(string $method, string $path, ?string $token = null, mixed $data = null, array $params = []): mixed
    {
        $headers = [
            'Content-Type' => 'application/json',
            'X-Uber-Eats-Sandbox' => config('services.uber_eats.sandbox') ? 'true' : 'false',
        ];

        if ($token) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        $options = [];
        if ($data !== null) {
            $options['json'] = $data;
        }
        if (! empty($params)) {
            $options['query'] = $params;
        }

        $response = Http::withHeaders($headers)
            ->timeout(15)
            ->send(strtoupper($method), $this->apiUrl().$path, $options);

        $response->throw();

        return $response->json();
    }

    public function errorMessage(Throwable $error): string
    {
        if ($error instanceof RequestException && $error->response) {
            $data = $error->response->json();

            return $data['message'] ?? $data['error']['message'] ?? $data['error'] ?? $error->response->body() ?: $error->getMessage();
        }

        if ($error instanceof ConnectionException) {
            return 'Could not reach Uber Eats: '.$error->getMessage();
        }

        return $error->getMessage() ?: 'Uber Eats request failed';
    }

    protected function platformConfig(Store $store): ?array
    {
        foreach ($store->platforms ?? [] as $platform) {
            if (($platform['platform'] ?? null) === 'uber_eats') {
                return $platform;
            }
        }

        return null;
    }

    protected function platformIndex(Store $store): int|false
    {
        foreach ($store->platforms ?? [] as $i => $platform) {
            if (($platform['platform'] ?? null) === 'uber_eats') {
                return $i;
            }
        }

        return false;
    }

    /**
     * Get a token for a store, caching it in the DB.
     */
    public function getStoreToken(Store $store): string
    {
        $config = $this->platformConfig($store);

        if (! empty($config['accessToken']) && ! empty($config['tokenExpiry'])) {
            try {
                if (Carbon::parse($config['tokenExpiry'])->isFuture()) {
                    return $config['accessToken'];
                }
            } catch (Throwable) {
                // fall through and refresh
            }
        }

        $auth = $this->getToken([
            'clientId' => $config['clientId'] ?? $config['apiKey'] ?? null,
            'clientSecret' => $config['clientSecret'] ?? $config['apiSecret'] ?? null,
        ]);

        $index = $this->platformIndex($store);
        if ($index !== false) {
            $platforms = $store->platforms;
            $platforms[$index]['accessToken'] = $auth['access_token'];
            $platforms[$index]['tokenExpiry'] = Carbon::now()->addSeconds((int) ($auth['expires_in'] ?? 3600))->toIso8601String();
            $store->platforms = $platforms;
            $store->save();
        }

        return $auth['access_token'];
    }

    /**
     * List the Uber Eats stores available to the given client credentials.
     */
    public function fetchStores(array $credentials): array
    {
        try {
            $auth = $this->getToken([
                'clientId' => $credentials['clientId'] ?? null,
                'clientSecret' => $credentials['clientSecret'] ?? null,
            ]);

            $res = $this->request('GET', '/eats/stores', $auth['access_token']);

            $stores = collect($res['stores'] ?? [])->map(fn ($s) => [
                'storeId' => $s['store_id'] ?? '',
                'name' => $s['name'] ?? null,
            ])->values()->all();

            return ['success' => true, 'message' => count($stores).' store(s) found', 'stores' => $stores];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    public function getStoreStatus(Store $store): array
    {
        $config = $this->platformConfig($store);
        if (! ($config['isConnected'] ?? false)) {
            return ['success' => false, 'message' => 'Uber Eats is not connected for this store'];
        }

        try {
            $token = $this->getStoreToken($store);
            $status = $this->request('GET', "/eats/stores/{$config['storeId']}/status", $token);

            return ['success' => true, 'message' => 'Store status retrieved', 'data' => $status];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    public function setStoreStatus(Store $store, array $status): array
    {
        $config = $this->platformConfig($store);
        if (! ($config['isConnected'] ?? false)) {
            return ['success' => false, 'message' => 'Uber Eats is not connected for this store'];
        }

        try {
            $token = $this->getStoreToken($store);
            $result = $this->request('POST', "/eats/stores/{$config['storeId']}/status", $token, $status);

            $store->isOnline = $status['is_online'] ?? $store->isOnline;
            $store->isBusy = $status['busy_mode'] ?? $store->isBusy;
            $store->isPaused = $status['pause_new_orders'] ?? $store->isPaused;
            $store->save();

            return ['success' => true, 'message' => 'Store status updated', 'data' => $result];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    public function getMenu(Store $store): array
    {
        $config = $this->platformConfig($store);
        if (! ($config['isConnected'] ?? false)) {
            return ['success' => false, 'message' => 'Uber Eats is not connected for this store'];
        }

        try {
            $token = $this->getStoreToken($store);
            $menu = $this->request('GET', "/eats/stores/{$config['storeId']}/menus", $token);

            return ['success' => true, 'message' => 'Menu retrieved', 'data' => $menu];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    public function pushMenu(Store $store, array $menu): array
    {
        $config = $this->platformConfig($store);
        if (! ($config['isConnected'] ?? false)) {
            return ['success' => false, 'message' => 'Uber Eats is not connected for this store'];
        }

        try {
            $token = $this->getStoreToken($store);
            $result = $this->request('PUT', "/eats/stores/{$config['storeId']}/menus", $token, $menu);

            return ['success' => true, 'message' => 'Menu pushed to Uber Eats', 'data' => $result];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    public function getOrders(Store $store): array
    {
        $config = $this->platformConfig($store);
        if (! ($config['isConnected'] ?? false)) {
            return ['success' => false, 'message' => 'Uber Eats is not connected for this store'];
        }

        try {
            $token = $this->getStoreToken($store);
            $orders = $this->request('GET', "/eats/stores/{$config['storeId']}/created-orders", $token);

            return ['success' => true, 'message' => 'Orders retrieved', 'data' => $orders];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    public function getOrderDetails(string $orderId): array
    {
        try {
            $order = $this->request('GET', "/eats/order/{$orderId}");

            return ['success' => true, 'message' => 'Order retrieved', 'data' => $order];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    public function acceptOrder(string $orderId): array
    {
        try {
            $result = $this->request('POST', "/eats/orders/{$orderId}/accept_pos_order");

            return ['success' => true, 'message' => 'Order accepted', 'data' => $result];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    public function denyOrder(string $orderId, ?string $reason = null): array
    {
        try {
            $result = $this->request('POST', "/eats/orders/{$orderId}/deny_pos_order", null, $reason ? ['reason' => $reason] : null);

            return ['success' => true, 'message' => 'Order denied', 'data' => $result];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    public function cancelOrder(string $orderId, ?array $reason = null): array
    {
        try {
            $result = $this->request('POST', "/eats/orders/{$orderId}/cancel", null, $reason ? ['cancellation_reason' => $reason] : null);

            return ['success' => true, 'message' => 'Order cancelled', 'data' => $result];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    public function setOrderStatus(string $orderId, string $status): array
    {
        try {
            $result = $this->request('POST', "/eats/orders/{$orderId}/restaurantdelivery/status", null, ['status' => $status]);

            return ['success' => true, 'message' => "Order marked as {$status}", 'data' => $result];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }
}
