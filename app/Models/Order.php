<?php

namespace App\Models;

use GazaDelivery\Foundation\Models\Staff;
use GazaDelivery\Foundation\Models\Store;
use Illuminate\Support\Str;

final class Order extends DeliveryModel
{
    protected $casts = ['return_policy_v2' => 'boolean', 'benefit_reserved' => 'boolean', 'delivered_at' => 'datetime', 'scheduled_for' => 'datetime'];

    protected static function booted(): void
    {
        self::creating(function (self $o) {
            $o->public_id ??= (string) Str::ulid();
            $o->tracking_token ??= bin2hex(random_bytes(24));
        });
    }

    public function getReferenceAttribute(): string
    {
        return $this->display_reference ?: ('MTJ-'.$this->created_at?->timezone('Asia/Hebron')->format('dmY').'-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT));
    }

    /** Immutable order origin, not the role of the current viewer or assigned captain. */
    public function getOriginCodeAttribute(): string
    {
        return match ($this->created_by_role ?? 'merchant') {
            'captain' => 'C',
            'admin' => 'A',
            default => 'M',
        };
    }

    public function getOriginLabelAttribute(): string
    {
        return match ($this->created_by_role ?? 'merchant') {
            'captain' => 'أنشأه الكابتن',
            'admin' => 'أنشأته الإدارة',
            default => 'أنشأه المتجر',
        };
    }

    /** A separately issued receipt number; the order number never changes. */
    public function getDeliveryReceiptReferenceAttribute(): ?string
    {
        return $this->delivery_reference;
    }

    public function getReferenceWithOriginAttribute(): string
    {
        return $this->reference.'-'.$this->origin_code;
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function captain()
    {
        return $this->belongsTo(Staff::class, 'captain_id');
    }

    public function events()
    {
        return $this->hasMany(OrderEvent::class)->orderBy('id');
    }

    public function issues()
    {
        return $this->hasMany(OrderIssue::class)->latest('id');
    }

    public function entry()
    {
        return $this->hasOne(FinancialEntry::class)->where('entry_kind', 'delivery');
    }

    public function financialEntries()
    {
        return $this->hasMany(FinancialEntry::class);
    }

    public function getServiceFeeWorkflowAttribute($value): bool
    {
        return (bool) $value;
    }

    public function getOperationalGoodsMinorAttribute(): int
    {
        return in_array($this->status, ['failed', 'office_pending', 'office_received', 'return_assigned', 'return_picked_up', 'return_transit', 'returned', 'cancelled'], true) ? 0 : (int) $this->goods_minor;
    }

    public function getOperationalCollectMinorAttribute(): int
    {
        if ($this->status === 'failed') {
            return \App\Services\Delivery\ServiceFees::attemptFee($this);
        }
        if (in_array($this->status, ['office_pending', 'office_received', 'return_assigned', 'return_picked_up', 'return_transit', 'returned'], true)) {
            return (int) $this->return_fee_minor;
        }

        return $this->status === 'cancelled' ? 0 : (int) $this->collect_minor;
    }

    public function returnTask()
    {
        return $this->hasOne(ReturnTask::class);
    }

    public function returnFeeReceivable()
    {
        return $this->hasOne(ReturnFeeReceivable::class);
    }

    public function payment()
    {
        return $this->hasOne(OrderPayment::class);
    }

    public function getDeliveryTimeLabelAttribute(): string
    {
        return $this->scheduled_for ? $this->scheduled_for->timezone('Asia/Hebron')->format('Y/m/d · H:i').' (توقيت فلسطين)' : 'حالا';
    }

    public function getLabelAttribute(): string
    {
        return config('delivery.order_statuses.'.$this->status, $this->status);
    }
}
