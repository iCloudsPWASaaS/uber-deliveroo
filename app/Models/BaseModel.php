<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model as MongoModel;

abstract class BaseModel extends MongoModel
{
    public function toArray(): array
    {
        return ['_id' => (string) $this->getKey()] + parent::toArray();
    }
}
