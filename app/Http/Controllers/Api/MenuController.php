<?php

namespace App\Http\Controllers\Api;

use App\Models\MenuItem;
use App\Models\Store;
use App\Services\DeliverooService;
use App\Services\UberEatsService;
use Illuminate\Http\Request;

class MenuController
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

        $items = MenuItem::where('storeId', $storeId)
            ->orderBy('category')
            ->orderBy('sortOrder')
            ->get();

        $categories = $items->pluck('category')->unique()->values()->all();

        return response()->json(['items' => $items, 'categories' => $categories]);
    }

    public function store(Request $request, string $storeId)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'category' => ['required', 'string'],
            'basePrice' => ['required', 'integer'],
            'description' => ['nullable', 'string'],
            'modifierGroups' => ['nullable', 'array'],
            'image' => ['nullable', 'string'],
            'allergens' => ['nullable', 'array'],
            'dietaryInfo' => ['nullable', 'array'],
            'isAvailable' => ['nullable', 'boolean'],
            'prepTime' => ['nullable', 'integer'],
        ]);

        $store = Store::find($storeId);
        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $platformPricing = collect($store->platforms ?? [])
            ->filter(fn ($p) => $p['isConnected'] ?? false)
            ->map(fn ($p) => [
                'platform' => $p['platform'],
                'price' => $data['basePrice'],
                'isAvailable' => $data['isAvailable'] ?? true,
            ])->values()->all();

        $item = MenuItem::create([
            'storeId' => $storeId,
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'category' => $data['category'],
            'basePrice' => $data['basePrice'],
            'modifierGroups' => $data['modifierGroups'] ?? [],
            'platformPricing' => $platformPricing,
            'image' => $data['image'] ?? null,
            'allergens' => $data['allergens'] ?? [],
            'dietaryInfo' => $data['dietaryInfo'] ?? [],
            'isAvailable' => $data['isAvailable'] ?? true,
            'prepTime' => $data['prepTime'] ?? ($store->averagePrepTime ?? 15),
        ]);

        return response()->json(['item' => $item], 201);
    }

    public function update(Request $request, string $storeId)
    {
        $store = Store::find($storeId);
        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $action = $request->input('action');
        $platform = $request->input('platform');

        if ($action === 'sync_to_platform') {
            $results = [];

            if (in_array($platform, ['uber_eats', 'both'], true)) {
                $menu = $request->input('menu') ?? $this->buildUberMenu($storeId);
                $results['uber_eats'] = $this->uber->pushMenu($store, $menu);
            }
            if (in_array($platform, ['deliveroo', 'both'], true)) {
                $menu = $request->input('menu') ?? $this->buildDeliverooMenu($storeId);
                $results['deliveroo'] = $this->deliveroo->pushMenu($store, $menu);
            }

            return response()->json(['results' => $results]);
        }

        if ($action === 'import_from_platform') {
            $results = [];

            if ($platform === 'uber_eats') {
                $results['uber_eats'] = $this->importUberMenu($storeId);
            } elseif ($platform === 'deliveroo') {
                $results['deliveroo'] = $this->importDeliverooMenu($storeId);
            }

            return response()->json(['results' => $results]);
        }

        return response()->json(['error' => 'Invalid action'], 400);
    }

    protected function buildUberMenu(string $storeId): array
    {
        $items = MenuItem::where('storeId', $storeId)->where('isAvailable', true)->get();

        return [
            'categories' => $items->pluck('category')->unique()->values()->map(function ($category) use ($items) {
                return [
                    'title' => $category,
                    'items' => $items->where('category', $category)->values()->map(fn ($i) => [
                        'title' => $i->name,
                        'description' => $i->description,
                        'price' => [
                            'amount' => $i->basePrice,
                            'currency' => $i->currency ?? 'GBP',
                        ],
                        'quantity_hint' => '1',
                        'item_id' => (string) $i->_id,
                        'available' => $i->isAvailable,
                    ])->all(),
                ];
            })->all(),
        ];
    }

    protected function buildDeliverooMenu(string $storeId): array
    {
        $items = MenuItem::where('storeId', $storeId)->where('isAvailable', true)->get();

        return [
            'items' => $items->map(fn ($i) => [
                'name' => $i->name,
                'description' => $i->description,
                'price' => $i->basePrice,
                'pos_id' => (string) $i->_id,
                'available' => $i->isAvailable,
                'category' => $i->category,
            ])->all(),
        ];
    }

    protected function importUberMenu(string $storeId): array
    {
        $store = Store::find($storeId);
        $result = $this->uber->getMenu($store);
        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $menu = $result['data'] ?? [];
        $count = 0;

        foreach ($menu['categories'] ?? [] as $category) {
            foreach ($category['items'] ?? [] as $item) {
                MenuItem::updateOrCreate(
                    ['storeId' => $storeId, 'name' => $item['title'] ?? '', 'category' => $category['title'] ?? ''],
                    [
                        'description' => $item['description'] ?? '',
                        'basePrice' => $item['price']['amount'] ?? 0,
                        'currency' => $item['price']['currency'] ?? 'GBP',
                        'isAvailable' => $item['available'] ?? true,
                        'platformPricing' => [],
                    ]
                );
                $count++;
            }
        }

        return ['success' => true, 'message' => "Imported {$count} Uber Eats items"];
    }

    protected function importDeliverooMenu(string $storeId): array
    {
        $store = Store::find($storeId);
        $result = $this->deliveroo->getMenu($store);
        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $menu = $result['data'] ?? [];
        $count = 0;

        foreach ($menu['items'] ?? [] as $item) {
            MenuItem::updateOrCreate(
                ['storeId' => $storeId, 'name' => $item['name'] ?? ''],
                [
                    'description' => $item['description'] ?? '',
                    'category' => $item['category'] ?? 'General',
                    'basePrice' => $item['price'] ?? 0,
                    'currency' => $item['currency'] ?? 'GBP',
                    'isAvailable' => $item['available'] ?? true,
                    'platformPricing' => [],
                ]
            );
            $count++;
        }

        return ['success' => true, 'message' => "Imported {$count} Deliveroo items"];
    }
}
