<?php

namespace App\Models;

use GazaDelivery\Foundation\Models\Staff;

final class Remittance extends DeliveryModel
{
    protected $casts = ['reviewed_at' => 'datetime', 'voided_at' => 'datetime'];

    /** Historical references are preserved; new operations have a distinct system reference. */
    public function getInternalReferenceAttribute(): ?string
    {
        return $this->system_reference ?: $this->reference;
    }

    public function captain()
    {
        return $this->belongsTo(Staff::class, 'captain_id');
    }
}
