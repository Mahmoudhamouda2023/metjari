<?php

namespace App\Models;

final class DeliveryNotification extends DeliveryModel
{
    protected $casts = ['read_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
