<?php

namespace App\Models;

use GazaDelivery\Foundation\Models\Store;

final class LoyaltyAccount extends DeliveryModel
{
    protected $casts = ['balance' => 'integer', 'reserved' => 'integer'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function getAvailableAttribute(): int
    {
        return (int) $this->balance - (int) $this->reserved;
    }
}
