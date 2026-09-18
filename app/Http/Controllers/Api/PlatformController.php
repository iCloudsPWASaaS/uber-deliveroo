<?php

namespace App\Http\Controllers\Api;

use App\Models\Store;
use App\Services\DeliverooService;
use App\Services\UberEatsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlatformController
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

        $platforms = collect($store->platforms ?? [])->map(fn ($p) => [
            'platform' => $p['platform'] ?? null,
            'isConnected' => $p['isConnected'] ?? false,
            'storeId' => $p['storeId'] ?? '',
            'hasWebhook' => ! empty($p['webhookSecret']),
        ])->values()->all();

        return response()->json(['platforms' => $platforms]);
    }

    public function connect(Request $request, string $storeId)
    {
        $platform = $request->input('platform');

        if (! in_array($platform, ['uber_eats', 'deliveroo'], true)) {
            return response()->json(['error' => 'Invalid platform'], 400);
        }

        $store = Store::find($storeId);
        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $credentials = $request->input('credentials', []);
        $config = ['isConnected' => true];

        if ($platform === 'uber_eats') {
            $config['storeId'] = $credentials['storeId'] ?? '';
            $config['clientId'] = $credentials['clientId'] ?? $credentials['apiKey'] ?? '';
            $config['clientSecret'] = $credentials['clientSecret'] ?? $credentials['apiSecret'] ?? '';
            $config['apiKey'] = $credentials['apiKey'] ?? $credentials['clientId'] ?? '';
            $config['apiSecret'] = $credentials['apiSecret'] ?? $credentials['clientSecret'] ?? '';
            $config['webhookSecret'] = $credentials['webhookSecret'] ?? $this->generateApiKey();
        } else {
            $config['storeId'] = $credentials['siteId'] ?? $credentials['storeId'] ?? '';
            $config['brandId'] = $credentials['brandId'] ?? '';
            $config['clientId'] = $credentials['clientId'] ?? '';
            $config['clientSecret'] = $credentials['clientSecret'] ?? '';
            $config['apiKey'] = $credentials['clientId'] ?? '';
            $config['apiSecret'] = $credentials['clientSecret'] ?? '';
            $config['webhookSecret'] = $credentials['webhookSecret'] ?? $this->generateApiKey();
        }

        $platforms = $store->platforms ?? [];
        $index = collect($platforms)->search(fn ($p) => ($p['platform'] ?? null) === $platform);

        if ($index === false) {
            $config['platform'] = $platform;
            $platforms[] = $config;
        } else {
            foreach ($config as $key => $value) {
                $platforms[$index][$key] = $value;
            }
        }

        $store->platforms = array_values($platforms);
        $store->save();

        return response()->json([
            'success' => true,
            'message' => ($platform === 'uber_eats' ? 'Uber Eats' : 'Deliveroo').' connected successfully',
            'webhookSecret' => $config['webhookSecret'],
            'storeId' => $config['storeId'],
        ]);
    }

    public function fetchUberStores(Request $request)
    {
        $request->validate([
            'clientId' => ['required', 'string'],
            'clientSecret' => ['required', 'string'],
        ]);

        $result = $this->uber->fetchStores([
            'clientId' => $request->input('clientId'),
            'clientSecret' => $request->input('clientSecret'),
        ]);

        if (! ($result['success'] ?? false)) {
            return response()->json(['error' => $result['message']], 502);
        }

        return response()->json(['stores' => $result['stores'] ?? []]);
    }

    public function fetchDeliverooSites(Request $request)
    {
        $request->validate([
            'clientId' => ['required', 'string'],
            'clientSecret' => ['required', 'string'],
        ]);

        $result = $this->deliveroo->fetchSites([
            'clientId' => $request->input('clientId'),
            'clientSecret' => $request->input('clientSecret'),
            'brandId' => $request->input('brandId'),
        ]);

        if (! ($result['success'] ?? false)) {
            return response()->json(['error' => $result['message']], 502);
        }

        return response()->json(['sites' => $result['sites'] ?? []]);
    }

    protected function generateApiKey(): string
    {
        return Str::random(48);
    }
}
