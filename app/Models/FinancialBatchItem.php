<?php

namespace App\Models;

final class FinancialBatchItem extends DeliveryModel
{
    public $timestamps = false;

    protected $casts = ['snapshot' => 'array'];
}
