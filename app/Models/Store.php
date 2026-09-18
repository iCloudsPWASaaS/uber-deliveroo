<?php

namespace App\Models;

class Store extends BaseModel
{
    protected $connection = 'mongodb';

    protected $collection = 'stores';

    protected $fillable = [
        'userId',
        'name',
        'description',
        'address',
        'city',
        'postcode',
        'phone',
        'email',
        'cuisine',
        'logo',
        'platforms',
        'operatingHours',
        'isOnline',
        'isBusy',
        'isPaused',
        'timezone',
        'averagePrepTime',
        'maxOrdersPerHour',
    ];

    protected $casts = [
        'cuisine' => 'array',
        'platforms' => 'array',
        'operatingHours' => 'array',
        'isOnline' => 'boolean',
        'isBusy' => 'boolean',
        'isPaused' => 'boolean',
        'averagePrepTime' => 'integer',
        'maxOrdersPerHour' => 'integer',
    ];

    public const CREATED_AT = 'createdAt';

    public const UPDATED_AT = 'updatedAt';

    public function menuItems()
    {
        return $this->hasMany(MenuItem::class, 'storeId', '_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'storeId', '_id');
    }
}
