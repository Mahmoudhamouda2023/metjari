<?php

namespace App\Models;

use GazaDelivery\Foundation\Models\Staff;
use GazaDelivery\Foundation\Models\Store;

final class RewardRequest extends DeliveryModel
{
    protected $casts = ['reviewed_at' => 'datetime'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(Staff::class, 'reviewed_by');
    }

    public function getStatusLabelAttribute(): string
    {
        return ['pending' => 'قيد المراجعة', 'approved' => 'تمت الموافقة', 'rejected' => 'مرفوض'][$this->status] ?? $this->status;
    }
}
