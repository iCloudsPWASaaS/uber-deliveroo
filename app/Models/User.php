<?php

namespace App\Models;

class User extends BaseModel
{
    protected $connection = 'mongodb';

    protected $collection = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'businessName',
        'phone',
        'role',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'createdAt' => 'datetime',
        'updatedAt' => 'datetime',
    ];

    public const CREATED_AT = 'createdAt';

    public const UPDATED_AT = 'updatedAt';
}
