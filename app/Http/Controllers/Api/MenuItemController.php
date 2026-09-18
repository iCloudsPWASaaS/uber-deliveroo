<?php

namespace App\Http\Controllers\Api;

use App\Models\MenuItem;
use App\Models\Store;
use Illuminate\Http\Request;

class MenuItemController
{
    public function show(string $storeId, string $itemId)
    {
        $store = Store::find($storeId);
        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $item = MenuItem::where('storeId', $storeId)->where('_id', $itemId)->first();
        if (! $item) {
            return response()->json(['error' => 'Menu item not found'], 404);
        }

        return response()->json(['item' => $item]);
    }

    public function update(Request $request, string $storeId, string $itemId)
    {
        $store = Store::find($storeId);
        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $item = MenuItem::where('storeId', $storeId)->where('_id', $itemId)->first();
        if (! $item) {
            return response()->json(['error' => 'Menu item not found'], 404);
        }

        $allowed = [
            'name', 'description', 'category', 'subcategory', 'basePrice', 'image',
            'modifierGroups', 'platformPricing', 'allergens', 'dietaryInfo',
            'isAvailable', 'prepTime', 'calories', 'sortOrder',
        ];

        foreach ($allowed as $field) {
            if ($request->has($field)) {
                $item->{$field} = $request->input($field);
            }
        }

        $item->save();

        return response()->json(['item' => $item]);
    }

    public function destroy(string $storeId, string $itemId)
    {
        $store = Store::find($storeId);
        if (! $store) {
            return response()->json(['error' => 'Store not found'], 404);
        }

        $item = MenuItem::where('storeId', $storeId)->where('_id', $itemId)->first();
        if (! $item) {
            return response()->json(['error' => 'Menu item not found'], 404);
        }

        $item->delete();

        return response()->json(['message' => 'Menu item deleted']);
    }
}
