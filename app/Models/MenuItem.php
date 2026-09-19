<?php

namespace App\Models;

class MenuItem extends BaseModel
{
    protected $connection = 'mongodb';

    protected $collection = 'menuitems';

    protected $fillable = [
        'storeId',
        'name',
        'description',
        'category',
        'subcategory',
        'basePrice',
        'currency',
        'image',
        'modifierGroups',
        'platformPricing',
        'allergens',
        'dietaryInfo',
        'isAvailable',
        'prepTime',
        'calories',
        'sortOrder',
        'externalId',
    ];

    protected $casts = [
        'modifierGroups' => 'array',
        'platformPricing' => 'array',
        'allergens' => 'array',
        'dietaryInfo' => 'array',
        'isAvailable' => 'boolean',
        'basePrice' => 'integer',
        'prepTime' => 'integer',
        'calories' => 'integer',
        'sortOrder' => 'integer',
    ];

    public const CREATED_AT = 'createdAt';

    public const UPDATED_AT = 'updatedAt';

    public function store()
    {
        return $this->belongsTo(Store::class, 'storeId', '_id');
    }
}
