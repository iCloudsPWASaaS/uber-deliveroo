<?php

namespace App\Http\Controllers\Api;

use App\Models\Store;
use App\Services\DeliverooService;
use App\Services\UberEatsService;
use Illuminate\Http\Request;

class StatusController
{
    public function __construct(
        protected UberEatsService $uber,
        protected DeliverooService $deliveroo,
    ) {}

    public function show(string $storeId)
    {
        $store = Store::find($storeId);
        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        return response()->json([
            'store' => [
                'isOnline' => $store->isOnline,
                'isBusy' => $store->isBusy,
                'isPaused' => $store->isPaused,
                'operatingHours' => $store->operatingHours,
            ],
        ]);
    }

    public function update(Request $request, string $storeId)
    {
        $store = Store::find($storeId);
        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        if ($request->has('isOnline')) {
            $store->isOnline = $request->boolean('isOnline');
        }
        if ($request->has('isBusy')) {
            $store->isBusy = $request->boolean('isBusy');
        }
        if ($request->has('isPaused')) {
            $store->isPaused = $request->boolean('isPaused');
        }
        $store->save();

        $results = [];

        if ($request->boolean('syncToPlatforms')) {
            $connected = collect($store->platforms ?? [])->filter(fn ($p) => $p['isConnected'] ?? false);

            foreach ($connected as $platform) {
                $payload = [
                    'is_online' => $store->isOnline,
                    'busy_mode' => $store->isBusy,
                    'pause_new_orders' => $store->isPaused,
                ];

                if (($platform['platform'] ?? null) === 'uber_eats') {
                    $results['uber_eats'] = $this->uber->setStoreStatus($store, $payload);
                } elseif (($platform['platform'] ?? null) === 'deliveroo') {
                    $results['deliveroo'] = $this->deliveroo->setSiteStatus($store, $payload);
                }
            }
        }

        return response()->json(['success' => true, 'results' => $results]);
    }
}
