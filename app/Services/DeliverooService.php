<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class DeliverooService
{
    public function apiUrl(): string
    {
        return rtrim(config('services.deliveroo.api_url', 'https://api.developers.deliveroo.com'), '/');
    }

    /**
     * OAuth token using Basic client credentials (used before a store is saved).
     */
    public function fetchToken(string $clientId, string $clientSecret): array
    {
        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->timeout(15)
            ->post($this->apiUrl().'/oauth/token', [
                'grant_type' => 'client_credentials',
            ]);

        $response->throw();

        return $response->json();
    }

    /**
     * Generic authenticated Deliveroo request.
     */
    public function request(string $method, string $endpoint, string $siteId, ?string $token = null, mixed $data = null): mixed
    {
        $headers = ['Content-Type' => 'application/json'];

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
            ->timeout(15)
            ->send(strtoupper($method), $this->apiUrl().'/'.$endpoint, $options);

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
            return 'Could not reach Deliveroo: '.$error->getMessage();
        }

        return $error->getMessage() ?: 'Deliveroo request failed';
    }

    /**
     * List the sites available to the given Deliveroo client credentials.
     */
    public function fetchSites(array $credentials): array
    {
        try {
            $tokenData = $this->fetchToken(
                $credentials['clientId'] ?? '',
                $credentials['clientSecret'] ?? ''
            );

            $endpoint = ! empty($credentials['brandId'])
                ? "brands/{$credentials['brandId']}/sites"
                : 'brands';

            $response = Http::withToken($tokenData['access_token'])
                ->timeout(15)
                ->get($this->apiUrl().'/'.$endpoint);

            $response->throw();

            $data = $response->json();

            if (is_array($data) && array_is_list($data)) {
                $rawSites = $data;
            } elseif (is_array($data) && isset($data['sites']) && is_array($data['sites'])) {
                $rawSites = $data['sites'];
            } elseif (is_array($data) && isset($data['brands']) && is_array($data['brands'])) {
                $rawSites = $data['brands'];
            } else {
                $rawSites = [];
            }

            $sites = collect($rawSites)
                ->filter(fn ($s) => ! empty($s['location_id']) || ! empty($s['id']))
                ->map(fn ($s) => [
                    'siteId' => $s['location_id'] ?? $s['id'] ?? '',
                    'name' => $s['name'] ?? null,
                    'status' => $s['status'] ?? null,
                ])->values()->all();

            return ['success' => true, 'message' => count($sites).' site(s) found', 'sites' => $sites];
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
     * Build the Deliveroo API path for a site operation.
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

        $index = $this->platformIndex($store);
        if ($index !== false) {
            $platforms = $store->platforms;
            $platforms[$index]['accessToken'] = $data['access_token'];
            $platforms[$index]['tokenExpiry'] = Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 3600))->toIso8601String();
            $store->platforms = $platforms;
            $store->save();
        }

        return $data['access_token'];
    }

    public function getSiteStatus(Store $store): array
    {
        $config = $this->platformConfig($store);
        if (! ($config['isConnected'] ?? false)) {
            return ['success' => false, 'message' => 'Deliveroo is not connected for this store'];
        }

        try {
            $token = $this->getStoreToken($store);
            $status = $this->request('GET', $this->sitePath($config, 'status'), $config['storeId'], $token);

            return ['success' => true, 'message' => 'Site status retrieved', 'data' => $status];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    public function setSiteStatus(Store $store, array $status): array
    {
        $config = $this->platformConfig($store);
        if (! ($config['isConnected'] ?? false)) {
            return ['success' => false, 'message' => 'Deliveroo is not connected for this store'];
        }

        try {
            $token = $this->getStoreToken($store);
            $result = $this->request('POST', $this->sitePath($config, 'status'), $config['storeId'], $token, $status);

            return ['success' => true, 'message' => 'Site status updated', 'data' => $result];
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
            $menu = $this->request('GET', $this->sitePath($config, 'menu'), $config['storeId'], $token);

            return ['success' => true, 'message' => 'Menu retrieved', 'data' => $menu];
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
            $result = $this->request('POST', $this->sitePath($config, 'menu'), $config['storeId'], $token, $menu);

            return ['success' => true, 'message' => 'Menu pushed to Deliveroo', 'data' => $result];
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
            $orders = $this->request('GET', $this->sitePath($config, 'orders'), $config['storeId'], $token);

            return ['success' => true, 'message' => 'Orders retrieved', 'data' => $orders];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    public function acceptOrder(Store $store, string $orderId): array
    {
        return $this->orderAction($store, $orderId, 'accept', 'Order accepted');
    }

    public function rejectOrder(Store $store, string $orderId): array
    {
        return $this->orderAction($store, $orderId, 'reject', 'Order rejected');
    }

    public function setOrderStatus(Store $store, string $orderId, string $status): array
    {
        $config = $this->platformConfig($store);
        if (! ($config['isConnected'] ?? false)) {
            return ['success' => false, 'message' => 'Deliveroo is not connected for this store'];
        }

        try {
            $token = $this->getStoreToken($store);
            $result = $this->request('POST', "orders/{$orderId}/status", $config['storeId'], $token, ['status' => $status]);

            return ['success' => true, 'message' => "Order status updated to {$status}", 'data' => $result];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }

    protected function orderAction(Store $store, string $orderId, string $action, string $message): array
    {
        $config = $this->platformConfig($store);
        if (! ($config['isConnected'] ?? false)) {
            return ['success' => false, 'message' => 'Deliveroo is not connected for this store'];
        }

        try {
            $token = $this->getStoreToken($store);
            $result = $this->request('POST', "orders/{$orderId}/{$action}", $config['storeId'], $token);

            return ['success' => true, 'message' => $message, 'data' => $result];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $this->errorMessage($error)];
        }
    }
}
