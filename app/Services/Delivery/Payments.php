<?php

namespace App\Services\Delivery;

use App\Models\Order;
use App\Models\OrderPayment;
use GazaDelivery\Foundation\Models\Staff;
use GazaDelivery\Foundation\Models\Store;
use GazaDelivery\Foundation\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class Payments
{
    private function details(array $input): array
    {
        $input['payment_method'] ??= 'cash';
        $input['payment_recipient'] ??= 'captain';
        $d = Validator::make($input, [
            'payment_method' => ['required', Rule::in(array_keys(config('delivery.payment_methods')))],
            'payment_recipient' => ['required', Rule::in(['captain', 'merchant'])],
            'payment_account' => ['nullable', 'string', 'max:160'],
            'payment_reference' => ['nullable', 'string', 'max:160'],
        ])->validate();
        if ($d['payment_method'] === 'cash') {
            if ($d['payment_recipient'] !== 'captain') {
                throw ValidationException::withMessages(['payment_recipient' => 'التحصيل النقدي يُسجل على الكابتن. اختر محفظة أو بنكًا للتحويل المباشر للتاجر.']);
            }

            return $d;
        }
        $d['payment_account'] = mb_strtoupper(preg_replace('/\s+/u', '', trim($d['payment_account'] ?? '')));
        $d['payment_reference'] = mb_strtoupper(trim($d['payment_reference'] ?? ''));
        Validator::make($d, [
            'payment_account' => ['required', 'string', 'min:6', 'max:160'],
            'payment_reference' => ['required', 'string', 'min:3', 'max:160'],
        ])->validate();

        return $d;
    }

    public function record(Staff|User $actor, Order $order, array $input): ?OrderPayment
    {
        $d = $this->details($input);
        if ($d['payment_method'] === 'cash') {
            return null;
        }
        abort_unless($actor instanceof Staff, 403);
        try {
            $p = new OrderPayment;
            $p->forceFill([
                'order_id' => $order->id, 'method' => $d['payment_method'],
                'recipient' => $d['payment_recipient'], 'amount_minor' => $order->collect_minor,
                'account_label' => $d['payment_account'], 'reference' => $d['payment_reference'],
                'status' => 'pending', 'reported_by' => $actor->id,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['payment_reference' => 'مرجع التحويل مسجل مسبقًا لهذا الحساب والوسيلة. لا تستخدم التحويل نفسه لأكثر من طلب.']);
        }
        Audit::log($actor, 'payment.reported', (string) $p->id, ['method' => $p->method, 'recipient' => $p->recipient, 'amount_minor' => $p->amount_minor]);

        return $p;
    }

    public function review(Staff $actor, OrderPayment $payment, string $decision, string $note): void
    {
        abort_unless($actor->isActive() && $actor->hasAnyRole(['super_admin', 'finance']), 403);
        Validator::make(['decision' => $decision, 'note' => $note], ['decision' => ['required', Rule::in(['approved', 'rejected'])], 'note' => ['required', 'string', 'min:3', 'max:500']])->validate();
        DB::transaction(function () use ($actor, $payment, $decision, $note) {
            $initialOrder = Order::findOrFail($payment->order_id);
            Store::whereKey($initialOrder->store_id)->lockForUpdate()->firstOrFail();
            Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $p = OrderPayment::lockForUpdate()->findOrFail($payment->id);
            if ($p->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'سبق اتخاذ قرار في هذه الدفعة.']);
            }
            $p->forceFill(['status' => $decision, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
            app(Loyalty::class)->sync($initialOrder);
            Audit::log($actor, 'payment.'.$decision, (string) $p->id, ['note' => $note]);
        }, 3);
    }

    public function correct(Staff $actor, OrderPayment $payment, array $input): void
    {
        $this->correctOrder($actor, Order::findOrFail($payment->order_id), $input);
    }

    public function correctOrder(Staff $actor, Order $order, array $input): void
    {
        $finance = $actor->isActive() && $actor->hasAnyRole(['super_admin', 'finance']);
        $d = $this->details($input);
        Validator::make($input, ['correction_reason' => [$finance ? 'required' : 'nullable', 'string', 'min:5', 'max:500']])->validate();
        if (! $finance && $d['payment_method'] === 'cash') {
            throw ValidationException::withMessages(['payment_method' => 'تغيير التحصيل إلى نقدي متاح للمالية فقط.']);
        }
        try {
            DB::transaction(function () use ($actor, $order, $d, $input, $finance) {
                Store::whereKey($order->store_id)->lockForUpdate()->firstOrFail();
                $o = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $p = OrderPayment::where('order_id', $o->id)->lockForUpdate()->first();
                abort_unless($finance || ($actor->isActive() && $actor->hasRole('captain') && $o->captain_id === $actor->id), 403);
                if (! $finance && $p?->status !== 'rejected') {
                    throw ValidationException::withMessages(['payment_reference' => 'التصحيح متاح بعد رفض الدفعة فقط.']);
                }
                $e = $o->entry()->lockForUpdate()->firstOrFail();
                if ($e->outcome !== 'delivered') {
                    throw ValidationException::withMessages(['payment_method' => 'تصحيح التحصيل متاح للطلبات المسلّمة فقط.']);
                }
                if ($e->remittance_id || $e->settlement_id || $e->captain_payout_id) {
                    throw ValidationException::withMessages(['payment_method' => 'الطلب مرتبط بكشف مالي. افتح الكشوف المرتبطة واعكس الدفعات بعد إعادة الأموال فعليًا، ثم صحّح التحصيل.']);
                }
                $old = ['payment' => $p?->getAttributes(), 'entry' => $e->getAttributes()];
                $p ??= new OrderPayment;
                $cash = $d['payment_method'] === 'cash';
                $p->forceFill([
                    'order_id' => $o->id, 'amount_minor' => $o->collect_minor,
                    'method' => $d['payment_method'], 'recipient' => $d['payment_recipient'],
                    'account_label' => $cash ? 'CASH-CAPTAIN-'.$o->captain_id : $d['payment_account'],
                    'reference' => $cash ? 'CASH-ORDER-'.$o->id : $d['payment_reference'],
                    'status' => $cash ? 'approved' : 'pending', 'reported_by' => $actor->id,
                    'reviewed_by' => $cash ? $actor->id : null, 'reviewed_at' => $cash ? now() : null,
                    'review_note' => $cash ? ($input['correction_reason'] ?? '') : null,
                ])->save();
                $direct = $p->recipient === 'merchant' ? (int) $p->amount_minor : 0;
                $captain = (int) $p->amount_minor - $direct;
                $e->forceFill(['collected_minor' => $captain, 'merchant_received_minor' => $direct, 'merchant_net_minor' => $captain - $e->fee_minor])->save();
                app(Loyalty::class)->sync($o);
                Audit::log($actor, 'payment.corrected', (string) $p->id, ['previous' => $old, 'new' => $p->getAttributes(), 'reason' => $input['correction_reason'] ?? 'تصحيح من الكابتن بعد الرفض']);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['payment_reference' => 'مرجع التحويل مستخدم مسبقًا لهذا الحساب.']);
        }
    }
}
