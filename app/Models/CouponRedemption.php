<?php

namespace App\Models;

final class CouponRedemption extends DeliveryModel
{
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
