<?php

namespace App\Services\Delivery;

use App\Models\CaptainPayout;
use App\Models\FinancialBatchItem;
use App\Models\FinancialEntry;
use App\Models\Order;
use App\Models\Remittance;
use App\Models\Settlement;
use GazaDelivery\Foundation\Models\Staff;
use GazaDelivery\Foundation\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinancialBatches
{
    public static function freeze(string $kind, int $batchId, $entries): void
    {
        foreach ($entries as $entry) {
            $item = new FinancialBatchItem;
            $item->forceFill(['kind' => $kind, 'batch_id' => $batchId, 'financial_entry_id' => $entry->id, 'snapshot' => $entry->getAttributes()])->save();
        }
    }

    public static function entries(string $kind, int $batchId)
    {
        return FinancialBatchItem::where('kind', $kind)->where('batch_id', $batchId)->get()->map(function ($item) {
            $entry = new FinancialEntry;
            $entry->setRawAttributes($item->snapshot, true);
            $entry->setRelation('order', Order::findOrFail($entry->order_id));

            return $entry;
        });
    }

    public function reverse(Staff $actor, string $kind, int $id, string $reason): void
    {
        abort_unless($actor->isActive() && $actor->hasAnyRole(['super_admin', 'finance']), 403);
        [$model, $column] = match ($kind) {
            'settlement' => [Settlement::class, 'settlement_id'],
            'captain' => [CaptainPayout::class, 'captain_payout_id'],
            'remittance' => [Remittance::class, 'remittance_id'],
            default => abort(404),
        };
        DB::transaction(function () use ($actor, $kind, $id, $reason, $model, $column) {
            $initial = $model::findOrFail($id);
            if ($kind === 'settlement') {
                Store::whereKey($initial->store_id)->lockForUpdate()->firstOrFail();
            } else {
                Staff::whereKey($initial->captain_id)->lockForUpdate()->firstOrFail();
            }
            $batch = $model::lockForUpdate()->findOrFail($id);
            if ($batch->voided_at || ($kind === 'remittance' && $batch->status !== 'approved')) {
                throw ValidationException::withMessages(['reason' => 'لا يمكن عكس هذا الكشف أو سبق عكسه.']);
            }
            $entries = FinancialEntry::where($column, $batch->id)->orderBy('id')->lockForUpdate()->get();
            if ($kind === 'remittance' && $entries->contains(fn ($e) => $e->settlement_id || $e->captain_payout_id)) {
                throw ValidationException::withMessages(['reason' => 'اعكس تسويات المتاجر وصرف الكباتن المرتبطة أولًا، بعد استرداد الأموال فعليًا.']);
            }
            if (! FinancialBatchItem::where('kind', $kind)->where('batch_id', $id)->exists()) {
                self::freeze($kind, $id, $entries);
            }
            $batch->forceFill(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason] + ($kind === 'remittance' ? ['status' => 'voided'] : []))->save();
            FinancialEntry::whereIn('id', $entries->modelKeys())->update([$column => null]);
            Audit::log($actor, 'finance.'.$kind.'.reversed', (string) $id, ['amount_minor' => $batch->amount_minor, 'reason' => $reason]);
        }, 3);
    }
}
