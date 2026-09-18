<?php

namespace App\Http\Controllers\Api;

use App\Models\Store;
use Illuminate\Http\Request;

class StoreController
{
    public function index()
    {
        return response()->json(['stores' => Store::query()->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'address' => ['required', 'string'],
            'city' => ['required', 'string'],
            'postcode' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'cuisine' => ['nullable', 'array'],
            'description' => ['nullable', 'string'],
            'userId' => ['nullable', 'string'],
        ]);

        $store = Store::create([
            'userId' => $data['userId'] ?? null,
            'name' => $data['name'],
            'address' => $data['address'],
            'city' => $data['city'],
            'postcode' => $data['postcode'] ?? '',
            'phone' => $data['phone'] ?? '',
            'email' => $data['email'] ?? '',
            'cuisine' => $data['cuisine'] ?? [],
            'description' => $data['description'] ?? '',
            'platforms' => [
                ['platform' => 'uber_eats', 'isConnected' => false],
                ['platform' => 'deliveroo', 'isConnected' => false],
            ],
            'operatingHours' => collect(range(0, 6))->map(fn ($day) => [
                'day' => $day,
                'open' => '09:00',
                'close' => '22:00',
                'isOpen' => true,
            ])->all(),
            'isOnline' => true,
            'isBusy' => false,
            'isPaused' => false,
            'timezone' => 'Europe/London',
            'averagePrepTime' => 15,
            'maxOrdersPerHour' => 30,
        ]);

        return response()->json(['store' => $store], 201);
    }

    public function show(string $storeId)
    {
        $store = Store::find($storeId);

        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        return response()->json(['store' => $store]);
    }

    public function update(Request $request, string $storeId)
    {
        $store = Store::find($storeId);

        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $allowed = [
            'name', 'description', 'address', 'city', 'postcode', 'phone', 'email',
            'cuisine', 'logo', 'isOnline', 'isBusy', 'isPaused', 'operatingHours',
            'timezone', 'averagePrepTime', 'maxOrdersPerHour',
        ];

        foreach ($allowed as $field) {
            if ($request->has($field)) {
                $store->{$field} = $request->input($field);
            }
        }

        $store->save();

        return response()->json(['store' => $store]);
    }

    public function destroy(string $storeId)
    {
        $store = Store::find($storeId);

        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $store->delete();

        return response()->json(['message' => 'Store deleted']);
    }
}
