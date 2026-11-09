<?php

namespace App\Services\Delivery;

use App\Models\FinancialEntry;
use App\Models\Order;
use GazaDelivery\Foundation\Models\Staff;
use GazaDelivery\Foundation\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ServiceFees
{
    public static function attemptFee(Order $order): int
    {
        return (int) ($order->agreed_fee_minor ?? (($order->delivery_credit_id || $order->coupon_id) ? $order->base_fee_minor : $order->fee_minor));
    }

    // The order and store are already locked by the transition transaction.
    public function record(Order $order, Staff $actor, string $stage, ?int $collected): FinancialEntry
    {
        $kind = $stage === 'failed' ? 'failed_delivery' : 'return_delivery';
        if ($existing = FinancialEntry::where('order_id', $order->id)->where('entry_kind', $kind)->first()) {
            return $existing;
        }
        $fee = $stage === 'failed' ? self::attemptFee($order) : (int) $order->return_fee_minor;
        $cash = $collected ?? 0;
        if (! in_array($cash, [0, $fee], true)) {
            throw ValidationException::withMessages(['collected_amount' => 'أدخل صفرًا إذا لم تُحصّل الرسوم، أو كامل أجرة هذه المرحلة: '.Money::show($fee).'. لا تُحصّل ثمن البضاعة.']);
        }
        $captainId = $stage === 'failed' ? $order->captain_id : $order->returnTask->captain_id;
        $entry = new FinancialEntry;
        $entry->forceFill([
            'order_id' => $order->id, 'entry_kind' => $kind, 'store_id' => $order->store_id,
            'captain_id' => $captainId, 'outcome' => $stage, 'fee_collection_required' => true,
            'service_fee_payer' => $stage === 'failed' ? 'recipient' : 'merchant',
            'collected_minor' => $cash, 'merchant_received_minor' => 0,
            'fee_minor' => $fee, 'captain_pay_minor' => $stage === 'failed' ? $order->captain_pay_minor : $order->captain_return_pay_minor,
            'merchant_net_minor' => 0, 'fee_collected_at' => $cash === $fee ? now() : null,
        ])->save();
        Audit::log($actor, 'service-fee.recorded', (string) $entry->id, ['stage' => $stage, 'fee_minor' => $fee, 'collected_minor' => $cash]);

        return $entry;
    }

    public function collect(Staff $actor, Order $order, int $entryId, int $amount): void
    {
        DB::transaction(function () use ($actor, $order, $entryId, $amount) {
            Store::whereKey($order->store_id)->lockForUpdate()->firstOrFail();
            Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $entry = FinancialEntry::where('order_id', $order->id)->where('fee_collection_required', true)->lockForUpdate()->findOrFail($entryId);
            abort_unless($actor->isActive() && $actor->hasRole('captain') && (int) $entry->captain_id === (int) $actor->id, 403);
            if ($amount !== (int) $entry->fee_minor) {
                throw ValidationException::withMessages(['collected_amount' => 'المبلغ يجب أن يطابق أجرة المرحلة كاملة.']);
            }
            if ($entry->fee_collected_at) {
                return;
            }
            if ($entry->remittance_id || $entry->captain_payout_id || $entry->settlement_id) {
                throw ValidationException::withMessages(['collected_amount' => 'المرحلة مرتبطة بكشف مالي؛ راجع المالية.']);
            }
            $entry->forceFill(['collected_minor' => $amount, 'fee_collected_at' => now()])->save();
            Audit::log($actor, 'service-fee.collected', (string) $entry->id, ['amount_minor' => $amount, 'payer' => $entry->service_fee_payer]);
        }, 3);
    }
}
