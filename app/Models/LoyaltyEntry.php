<?php

namespace App\Models;

final class LoyaltyEntry extends DeliveryModel
{
    protected $casts = ['points' => 'integer'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
