<?php

namespace App\Models;

final class FinancialEntry extends DeliveryModel
{
    protected $casts = ['fee_collection_required' => 'boolean', 'fee_collected_at' => 'datetime'];

    public function getStageLabelAttribute(): string
    {
        return match ($this->entry_kind) {
            'failed_delivery' => 'أجرة محاولة التسليم',
            'return_delivery' => 'أجرة إرجاع للمتجر',
            'return_captain' => 'حصة كابتن الإرجاع السابقة',
            default => $this->outcome === 'delivered' ? 'تسليم' : 'إعادة سابقة',
        };
    }
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function remittance()
    {
        return $this->belongsTo(Remittance::class);
    }

    public function scopePaymentVerified($q)
    {
        return $q->whereHas('order', fn ($o) => $o->where(fn ($b) => $b->whereDoesntHave('payment')->orWhereHas('payment', fn ($p) => $p->where('status', 'approved'))));
    }

    public function scopeCleared($q)
    {
        return $q->paymentVerified()->where(fn ($b) => $b->where('fee_collection_required', false)->orWhereNotNull('fee_collected_at'))
            ->where(fn ($b) => $b->where('collected_minor', 0)->orWhereHas('remittance', fn ($r) => $r->where('status', 'approved')));
    }
}
