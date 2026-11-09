<?php

namespace App\Models;

use GazaDelivery\Foundation\Models\Store;

final class Settlement extends DeliveryModel
{
    protected $casts = ['voided_at' => 'datetime'];

    /** Historical references are preserved; new operations have a distinct system reference. */
    public function getInternalReferenceAttribute(): ?string
    {
        return $this->system_reference ?: $this->reference;
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
