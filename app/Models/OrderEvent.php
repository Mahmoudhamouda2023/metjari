<?php

namespace App\Models;

final class OrderEvent extends DeliveryModel
{
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime'];
}
