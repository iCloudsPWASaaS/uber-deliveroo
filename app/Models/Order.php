<?php

namespace App\Models;

class Order extends BaseModel
{
    protected $connection = 'mongodb';

    protected $collection = 'orders';

    public const STATUSES = [
        'received',
        'accepted',
        'preparing',
        'ready',
        'picked_up',
        'delivered',
        'cancelled',
    ];

    protected $fillable = [
        'storeId',
        'userId',
        'orderNumber',
        'platform',
        'platformInfo',
        'customer',
        'items',
        'subtotal',
        'deliveryFee',
        'serviceFee',
        'total',
        'currency',
        'status',
        'statusHistory',
        'specialInstructions',
        'estimatedReadyTime',
        'actualReadyTime',
        'scheduledDeliveryTime',
        'cancellationReason',
    ];

    protected $casts = [
        'platformInfo' => 'array',
        'customer' => 'array',
        'items' => 'array',
        'statusHistory' => 'array',
        'subtotal' => 'integer',
        'deliveryFee' => 'integer',
        'serviceFee' => 'integer',
        'total' => 'integer',
        'estimatedReadyTime' => 'datetime',
        'actualReadyTime' => 'datetime',
        'scheduledDeliveryTime' => 'datetime',
    ];

    public const CREATED_AT = 'createdAt';

    public const UPDATED_AT = 'updatedAt';

    public function store()
    {
        return $this->belongsTo(Store::class, 'storeId', '_id');
    }

    public function recordStatus(string $status, ?string $note = null): void
    {
        $history = $this->statusHistory ?? [];
        $history[] = [
            'status' => $status,
            'timestamp' => now(),
            'note' => $note,
        ];

        $this->status = $status;
        $this->statusHistory = $history;

        if ($status === 'ready') {
            $this->actualReadyTime = now();
        }
    }
}
