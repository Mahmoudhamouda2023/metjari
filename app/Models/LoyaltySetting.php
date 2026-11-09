<?php

namespace App\Models;

final class LoyaltySetting extends DeliveryModel
{
    protected $casts = ['enabled' => 'boolean', 'include_free_orders' => 'boolean'];
}
