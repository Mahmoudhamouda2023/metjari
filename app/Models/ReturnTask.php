<?php

namespace App\Models;

use GazaDelivery\Foundation\Models\Staff;

final class ReturnTask extends DeliveryModel
{
    protected $casts = ['office_handed_at' => 'datetime', 'office_received_at' => 'datetime', 'assigned_at' => 'datetime', 'picked_up_at' => 'datetime', 'departed_at' => 'datetime', 'completed_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function captain()
    {
        return $this->belongsTo(Staff::class, 'captain_id');
    }

    public function receiver()
    {
        return $this->belongsTo(Staff::class, 'received_by');
    }
}
