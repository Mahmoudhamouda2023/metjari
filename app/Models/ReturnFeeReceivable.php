<?php

namespace App\Models;

final class ReturnFeeReceivable extends DeliveryModel
{
    protected $fillable = ['order_id', 'payer', 'goods_due_minor', 'fee_due_minor', 'collected_minor'];
    protected $casts = ['collected_at' => 'datetime'];
    public function order() { return $this->belongsTo(Order::class); }
    public function getOutstandingMinorAttribute(): int { return max(0, (int)$this->fee_due_minor - (int)$this->collected_minor); }
}
