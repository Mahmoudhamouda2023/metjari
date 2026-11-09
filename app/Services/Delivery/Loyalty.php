<?php

namespace App\Services\Delivery;

use App\Models\Coupon;
use App\Models\DeliveryNotification;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyEntry;
use App\Models\LoyaltySetting;
use App\Models\Order;
use App\Models\RewardRequest;
use GazaDelivery\Foundation\Models\Staff;
use GazaDelivery\Foundation\Models\Store;
use GazaDelivery\Foundation\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class Loyalty
{
    public function settings(): LoyaltySetting
    {
        return LoyaltySetting::findOrFail(1);
    }

    // Caller serializes all account changes on the store, including requests and corrections.
    private function account(int $storeId): LoyaltyAccount
    {
        $account = LoyaltyAccount::where('store_id', $storeId)->lockForUpdate()->first();
        if (! $account) {
            $account = new LoyaltyAccount;
            $account->forceFill(['store_id' => $storeId, 'balance' => 0, 'reserved' => 0])->save();
        }

        return $account;
    }

    public function sync(Order $order): void
    {
        DB::transaction(function () use ($order) {
            Store::whereKey($order->store_id)->lockForUpdate()->firstOrFail();
            $o = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $verified = ! $o->payment || $o->payment->status === 'approved';
            $target = $o->status === 'delivered' && $verified ? (int) $o->loyalty_points : 0;
            $previous = (int) LoyaltyEntry::where('order_id', $o->id)->sum('points');
            $delta = $target - $previous;
            if ($delta === 0) {
                return;
            }
            $account = $this->account($o->store_id);
            $account->balance += $delta;
            $account->save();
            $entry = new LoyaltyEntry;
            $entry->forceFill(['store_id' => $o->store_id, 'order_id' => $o->id, 'points' => $delta, 'reason' => $delta > 0 ? 'نقاط طلب مسلّم ومؤكد التحصيل' : 'عكس نقاط بسبب تصحيح التحقق من التحصيل'])->save();
            $this->notifyMerchant($o->store, ($delta > 0 ? 'أضيفت ' : 'عُكست ').abs($delta).' نقطة للطلب '.$o->reference);
        }, 3);
    }

    public function request(User $actor, string $key, int $expectedPoints): RewardRequest
    {
        abort_unless($actor->isActive() && $actor->store?->status->value === 'active', 403);

        return DB::transaction(function () use ($actor, $key, $expectedPoints) {
            $store = Store::where('owner_user_id', $actor->id)->lockForUpdate()->firstOrFail();
            if ($existing = RewardRequest::where('store_id', $store->id)->where('submission_key', $key)->first()) {
                return $existing;
            }
            $settings = LoyaltySetting::whereKey(1)->lockForUpdate()->firstOrFail();
            if (! $settings->enabled) {
                $this->fail('points', 'نظام النقاط موقوف حاليًا. رصيدك محفوظ.');
            }
            if ($expectedPoints !== (int) $settings->reward_points) {
                $this->fail('points', 'تغيّرت شروط المكافأة. حدّث الصفحة وراجع النقاط المطلوبة.');
            }
            $account = $this->account($store->id);
            if ($account->available < $settings->reward_points) {
                $this->fail('points', 'الرصيد المتاح غير كافٍ أو توجد نقاط محجوزة لمراجعة سابقة.');
            }
            $request = new RewardRequest;
            $request->forceFill(['store_id' => $store->id, 'requested_by' => $actor->id, 'submission_key' => $key, 'points' => $settings->reward_points, 'coupon_days' => $settings->coupon_days, 'status' => 'pending'])->save();
            $account->reserved += (int) $request->points;
            $account->save();
            Staff::where('status', 'active')->role(['super_admin', 'finance'])->select('staff.*')->distinct()->get()->each(function ($staff) use ($store, $request) {
                $n = new DeliveryNotification;
                $n->forceFill(['staff_id' => $staff->id, 'title' => 'طلب استبدال '.$request->points.' نقطة من '.$store->name, 'target_url' => route('admin.rewards.index', ['request_id' => $request->id])])->save();
            });

            return $request;
        }, 3);
    }

    public function review(Staff $actor, RewardRequest $request, string $decision, string $note): RewardRequest
    {
        abort_unless($actor->isActive() && $actor->hasAnyRole(['super_admin', 'finance']), 403);
        abort_unless(in_array($decision, ['approved', 'rejected'], true), 422);
        if (mb_strlen(trim($note)) < 3 || mb_strlen($note) > 500) {
            $this->fail('review_note', 'اكتب ملاحظة مراجعة من 3 إلى 500 حرف.');
        }

        return DB::transaction(function () use ($actor, $request, $decision, $note) {
            $store = Store::whereKey($request->store_id)->lockForUpdate()->firstOrFail();
            $r = RewardRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'pending') {
                $this->fail('decision', 'سبق اتخاذ قرار في هذا الطلب.');
            }
            $account = $this->account($r->store_id);
            if ($account->reserved < $r->points) {
                $this->fail('decision', 'حجز النقاط غير متطابق؛ راجع الرصيد.');
            }
            if ($decision === 'approved') {
                if ($account->balance < $account->reserved) {
                    $this->fail('decision', 'تغيّر الرصيد بعد تصحيح تحصيل أحد الطلبات. ارفض الطلب لتحرير الحجز.');
                }
                $coupon = new Coupon;
                $coupon->forceFill(['code' => 'GIFT-'.strtoupper((string) Str::ulid()), 'store_id' => $store->id, 'type' => 'percent', 'value' => 10000, 'min_fee_minor' => 0, 'max_uses' => 1, 'per_store_limit' => 1, 'starts_on' => now('Asia/Hebron')->toDateString(), 'ends_on' => now('Asia/Hebron')->addDays($r->coupon_days)->toDateString(), 'active' => true])->save();
                $r->coupon_id = $coupon->id;
                $account->balance -= (int) $r->points;
                $entry = new LoyaltyEntry;
                $entry->forceFill(['store_id' => $store->id, 'reward_request_id' => $r->id, 'points' => -(int) $r->points, 'reason' => 'استبدال نقاط بكوبون توصيل مجاني'])->save();
            }
            $account->reserved -= (int) $r->points;
            $account->save();
            $r->forceFill(['status' => $decision, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => trim($note)])->save();
            Audit::log($actor, 'reward.'.$decision, (string) $r->id, ['points' => $r->points, 'coupon_id' => $r->coupon_id]);
            $this->notifyMerchant($store, $decision === 'approved' ? 'تم تفعيل هدية توصيل مجاني. كودك: '.$r->coupon->code : 'رُفض طلب الاستبدال وأعيدت النقاط المتاحة. السبب: '.$note);

            return $r;
        }, 3);
    }

    private function notifyMerchant(Store $store, string $title): void
    {
        $n = new DeliveryNotification;
        $n->forceFill(['user_id' => $store->owner_user_id, 'title' => mb_substr($title, 0, 240), 'target_url' => route('merchant.points')])->save();
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
