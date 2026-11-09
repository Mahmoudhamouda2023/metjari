<?php

namespace App\Services\Delivery;

use App\Models\CouponRedemption;
use App\Models\DeliveryCredit;
use App\Models\FinancialEntry;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\ReturnFeeReceivable;
use App\Models\Subscription;
use GazaDelivery\Foundation\Models\Staff;
use GazaDelivery\Foundation\Models\Store;
use GazaDelivery\Foundation\Models\User;
use GazaDelivery\Foundation\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderWorkflow
{
    public function create(User $actor, array $data): Order
    {
        return DB::transaction(function () use ($actor, $data) {
            $store = Store::where('owner_user_id', $actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->fresh()->isActive() && $store->status->value === 'active', 403);
            if ($old = Order::where('store_id', $store->id)->where('idempotency_key', $data['idempotency_key'])->first()) {
                return $old;
            }
            $schedule = OrderSchedule::attributes($data);
            $price = app(Pricing::class)->quote($store, $data);
            if (isset($data['quote_hash']) && ! hash_equals(Pricing::fingerprint($price), $data['quote_hash'])) {
                $this->fail('price', 'تغيّر السعر أو شروط التوصيل. ارجع إلى نموذج الطلب وراجع السعر من جديد.');
            }
            if (isset($data['expected_fee']) && (int) $data['expected_fee'] !== $price['fee_minor']) {
                $this->fail('price', 'تغيّر السعر أو رصيد المزايا. راجع السعر من جديد قبل التأكيد.');
            }
            if ($price['delivery_credit_id']) {
                DeliveryCredit::whereKey($price['delivery_credit_id'])->decrement('remaining');
            }
            if ($price['subscription_id']) {
                Subscription::whereKey($price['subscription_id'])->decrement('remaining_deliveries');
            }
            $order = new Order;
            $order->forceFill($price + $schedule + [
                'store_id' => $store->id,
                'created_by_role' => 'merchant',
                'idempotency_key' => $data['idempotency_key'],
                'to_zone_id' => $data['to_zone_id'],
                'recipient_name' => $data['recipient_name'],
                'recipient_phone' => PhoneNumber::normalize($data['recipient_phone']),
                'recipient_address' => $data['recipient_address'],
                'description' => $data['description'],
                'merchant_reference' => $data['merchant_reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'fee_payer' => $data['fee_payer'],
                'status' => 'created',
                'return_policy_v2' => true,
                'service_fee_workflow' => true,
                'loyalty_points' => ($settings = app(Loyalty::class)->settings())->enabled && ($price['fee_minor'] > 0 || $settings->include_free_orders) ? $settings->points_per_order : 0,
            ])->save();
            if ($price['coupon_id']) {
                $r = new CouponRedemption;
                $r->forceFill(['coupon_id' => $price['coupon_id'], 'store_id' => $store->id, 'order_id' => $order->id])->save();
            }
            $order->forceFill(['display_reference' => app(StoreReference::class)->code($store).'-'.$order->created_at->timezone('Asia/Hebron')->format('dmY').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)])->save();
            $this->event($order, $actor, 'تم إنشاء الطلب');
            app(OrderNotifications::class)->created($order);

            return $order;
        }, 3);
    }

    public function assign(Staff $actor, Order $order, int $captainId): Order
    {
        abort_unless($actor->isActive() && $actor->hasAnyRole(['super_admin', 'operations']), 403);

        return DB::transaction(function () use ($actor, $order, $captainId) {
            $o = Order::lockForUpdate()->findOrFail($order->id);
            if (! in_array($o->status, ['created', 'assigned'], true)) {
                $this->fail('status', 'الإسناد متاح قبل استلام الطرد فقط.');
            }
            $captain = Staff::whereKey($captainId)->lockForUpdate()->firstOrFail();
            if (! $captain->isActive() || ! $captain->hasRole('captain') || ! $captain->captainProfile) {
                $this->fail('captain_id', 'اختر كابتنًا مفعّلًا لديه بيانات توصيل.');
            }
            $notify = $o->captain_id !== $captainId || $o->status !== 'assigned';
            $o->forceFill(['captain_id' => $captainId, 'status' => 'assigned'])->save();
            $this->event($o, $actor, 'إسناد الطلب إلى '.$captain->name);
            if ($notify) {
                app(OrderNotifications::class)->assigned($o, $captain);
            }

            return $o;
        }, 3);
    }

    public function transition(Staff|User $actor, Order $order, string $target, ?string $note = null, ?int $collected = null, array $payment = []): Order
    {
        return DB::transaction(function () use ($actor, $order, $target, $note, $collected, $payment) {
            // All benefit allocation and release serializes on the store row.
            Store::whereKey($order->store_id)->lockForUpdate()->firstOrFail();
            $o = Order::lockForUpdate()->findOrFail($order->id);
            abort_unless(in_array($target, OrderActions::allowed($actor, $o), true), 403, 'هذا الإجراء غير متاح لحسابك أو لحالة الطلب الحالية.');
            if (in_array($target, ['failed', 'office_pending', 'office_received', 'returned', 'cancelled'], true) && mb_strlen(trim($note ?? '')) < 3) {
                $this->fail('note', 'اكتب سببًا واضحًا أو تأكيد إعادة الطرد للمتجر.');
            }
            if ($target === 'delivered' && $collected !== (int) $o->collect_minor) {
                $this->fail('collected_amount', 'المبلغ المحصّل يجب أن يساوي '.Money::show($o->collect_minor).'.');
            }
            if ($target === 'out_for_delivery' && $o->service_fee_workflow && $o->status === 'failed') {
                $feeEntry = $o->financialEntries()->where('entry_kind', 'failed_delivery')->lockForUpdate()->first();
                if ($feeEntry) {
                    if ($feeEntry->fee_collected_at || $feeEntry->remittance_id || $feeEntry->captain_payout_id || $feeEntry->settlement_id) {
                        $this->fail('status', 'تم تحصيل أو تسوية أجرة المحاولة؛ أكمل مسار الإرجاع ولا تُعد تحصيلها.');
                    }
                    Audit::log($actor, 'service-fee.cancelled-for-retry', (string) $feeEntry->id, $feeEntry->getAttributes());
                    $feeEntry->delete();
                }
            }
            $o->status = $target;
            if ($target === 'delivered') {
                $o->delivered_at = now();
            }
            if (in_array($target, ['cancelled', 'returned'], true)) {
                $this->release($o);
            }
            if ($target === 'delivered') {
                $o->benefit_reserved = false;
            }
            $o->save();
            if ($target === 'delivered' && ! $o->delivery_reference) {
                // Separate from the immutable store order ID and from actual bank transfer IDs.
                $o->forceFill(['delivery_reference' => OperationReference::issue('delivery', $o, $o->delivered_at)])->save();
            }
            if ($actor instanceof Staff) {
                app(Returns::class)->record($o, $actor, $target);
            }
            $this->event($o, $actor, $note);
            app(OrderNotifications::class)->changed($o, $target);
            if ($o->service_fee_workflow && in_array($target, ['failed', 'returned'], true)) {
                // Old open returns upgraded by migration have no recorded collection.
                if ($target === 'returned' && ! $o->financialEntries()->where('entry_kind', 'failed_delivery')->exists()) {
                    app(ServiceFees::class)->record($o, $actor, 'failed', 0);
                }
                app(ServiceFees::class)->record($o, $actor, $target, $collected);
            }
            if ($target === 'delivered' || ($target === 'returned' && ! $o->service_fee_workflow)) {
                $isDelivery = $target === 'delivered';
                $receipt = $isDelivery ? app(Payments::class)->record($actor, $o, $payment) : null;
                $direct = $receipt?->recipient === 'merchant' ? (int) $o->collect_minor : 0;
                $cash = $isDelivery ? (int) $o->collect_minor - $direct : 0;
                $fee = $isDelivery
                    ? (int) $o->fee_minor
                    : ($o->return_policy_v2 && ($o->delivery_credit_id || $o->coupon_id) ? (int) $o->base_fee_minor : (int) $o->fee_minor) + (int) $o->return_fee_minor;
                $captainPay = $isDelivery ? (int) $o->captain_pay_minor : (int) $o->captain_return_pay_minor;
                $returnCaptain = $o->returnTask?->captain_id;
                $extraPay = 0;
                if (! $isDelivery && $returnCaptain && (int) $returnCaptain !== (int) $o->captain_id) {
                    $deliveryShare = Money::percent($captainPay, (int) $o->return_delivery_share_bps);
                    $extraPay = $captainPay - $deliveryShare;
                    $captainPay = $deliveryShare;
                }
                if (! $isDelivery) {
                    // A receivable is NOT a receipt: no money is recorded as collected here.
                    ReturnFeeReceivable::firstOrCreate(['order_id' => $o->id], [
                        'payer' => $o->fee_payer === 'merchant' ? 'merchant' : 'recipient',
                        'goods_due_minor' => 0,
                        'fee_due_minor' => $fee,
                        'collected_minor' => 0,
                    ]);
                }
                $entry = new FinancialEntry;
                $entry->forceFill(['order_id' => $o->id, 'store_id' => $o->store_id, 'captain_id' => $o->captain_id, 'outcome' => $target, 'collected_minor' => $cash, 'merchant_received_minor' => $direct, 'fee_minor' => $fee, 'captain_pay_minor' => $captainPay, 'merchant_net_minor' => $cash - ($isDelivery || $o->fee_payer === 'merchant' ? $fee : 0)])->save();
            }

            if (isset($extraPay) && $extraPay > 0) {
                $extra = new FinancialEntry;
                $extra->forceFill(['order_id' => $o->id, 'entry_kind' => 'return_captain', 'store_id' => $o->store_id, 'captain_id' => $returnCaptain, 'outcome' => 'returned', 'collected_minor' => 0, 'merchant_received_minor' => 0, 'fee_minor' => 0, 'captain_pay_minor' => $extraPay, 'merchant_net_minor' => 0])->save();
            }
            if ($target === 'delivered') {
                app(Loyalty::class)->sync($o);
            }

            return $o;
        }, 3);
    }

    private function release(Order $o): void
    {
        if ($o->benefit_reserved && $o->subscription_id) {
            Subscription::whereKey($o->subscription_id)->increment('remaining_deliveries');
        }
        if ($o->benefit_reserved && $o->delivery_credit_id) {
            DeliveryCredit::whereKey($o->delivery_credit_id)->increment('remaining');
        }
        $o->benefit_reserved = false;
        CouponRedemption::where('order_id', $o->id)->delete();
    }

    private function event(Order $o, Staff|User $actor, ?string $note): void
    {
        $e = new OrderEvent;
        $e->forceFill(['order_id' => $o->id, 'status' => $o->status, 'note' => $note, 'staff_id' => $actor instanceof Staff ? $actor->id : null, 'user_id' => $actor instanceof User ? $actor->id : null])->save();
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
