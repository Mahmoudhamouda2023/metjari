<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\FinancialEntry;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyEntry;
use App\Models\LoyaltySetting;
use App\Models\Order;
use App\Models\PickupAddress;
use App\Models\RewardRequest;
use App\Models\Tariff;
use App\Models\Zone;
use App\Services\Delivery\Finance;
use App\Services\Delivery\Loyalty;
use App\Services\Delivery\OrderWorkflow;
use App\Services\Delivery\Payments;
use App\Services\Delivery\Returns;
use GazaDelivery\Foundation\Models\Staff;
use GazaDelivery\Foundation\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReturnsAndLoyaltyTest extends TestCase
{
    private User $owner;

    private Staff $admin;

    private Staff $captain;

    private Zone $from;

    private Zone $to;

    private PickupAddress $address;

    private Tariff $tariff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->merchant(true);
        $this->admin = $this->staff();
        $this->captain = $this->staff('captain', '0591000002');
        $this->from = $this->make(Zone::class, ['name' => 'غزة', 'active' => true]);
        $this->to = $this->make(Zone::class, ['name' => 'الشمال', 'active' => true]);
        $this->address = $this->make(PickupAddress::class, ['store_id' => $this->owner->store->id, 'zone_id' => $this->from->id, 'label' => 'الفرع الرئيسي', 'address' => 'شارع الجلاء، قرب المفترق', 'contact_phone' => '0591000009', 'active' => true]);
        $this->tariff = $this->make(Tariff::class, ['from_zone_id' => $this->from->id, 'to_zone_id' => $this->to->id, 'fee_minor' => 1000, 'captain_pay_minor' => 600, 'return_fee_minor' => 300, 'captain_return_pay_minor' => 200, 'active' => true]);
    }

    private function make(string $model, array $data)
    {
        $m = new $model;
        $m->forceFill($data)->save();

        return $m->fresh();
    }

    private function data(array $extra = []): array
    {
        return array_replace(['idempotency_key' => (string) Str::uuid(), 'pickup_address_id' => $this->address->id, 'to_zone_id' => $this->to->id, 'recipient_name' => 'مستلم تجريبي', 'recipient_phone' => '0593334444', 'recipient_address' => 'بيت قرب المسجد، الشارع الرئيسي', 'description' => 'طرد ملابس', 'fee_payer' => 'merchant', 'goods_amount' => '100.00'], $extra);
    }

    private function order(array $data = []): Order
    {
        // Existing tests in this class retain the pre-upgrade policy as historical fixtures.
        $order = app(OrderWorkflow::class)->create($this->owner, $this->data($data));
        $order->forceFill(['service_fee_workflow' => false])->save();

        return $order;
    }

    private function delivered(array $data = []): Order
    {
        $w = app(OrderWorkflow::class);
        $o = $this->order($data);
        $o = $w->assign($this->admin, $o, $this->captain->id);
        foreach (['picked_up', 'out_for_delivery'] as $s) {
            $o = $w->transition($this->captain, $o, $s);
        }

        return $w->transition($this->captain, $o, 'delivered', null, $o->collect_minor);
    }

    private function enable(array $extra = []): void
    {
        LoyaltySetting::findOrFail(1)->forceFill(array_replace(['enabled' => true, 'points_per_order' => 2, 'reward_points' => 10, 'coupon_days' => 30], $extra))->save();
    }

    private function earn(int $count = 5): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->delivered();
        }
    }

    private function requestReward(): RewardRequest
    {
        return app(Loyalty::class)->request($this->owner, (string) Str::uuid(), 10);
    }

    private function failed(): Order
    {
        $w = app(OrderWorkflow::class);
        $o = $w->assign($this->admin, $this->order(), $this->captain->id);
        $o = $w->transition($this->captain, $o, 'picked_up');

        return $w->transition($this->captain, $o, 'failed', 'المستلم غير متاح');
    }

    private function office(): Order
    {
        $w = app(OrderWorkflow::class);
        $o = $this->failed();
        $o = $w->transition($this->captain, $o, 'office_pending', 'سلمت الطرد للمكتب');

        return $w->transition($this->admin, $o, 'office_received', 'استلم المكتب الطرد');
    }

    public function test_complete_return_with_two_captains_keeps_one_merchant_charge_and_splits_pay(): void
    {
        $this->enable();
        $other = $this->staff('captain', '0591000003');
        $o = $this->office();
        $ref = $o->reference;
        app(Returns::class)->assign($this->admin, $o, $other->id);
        $this->assertSame($this->captain->id, $o->fresh()->captain_id);
        $this->asStaff($other)->get(route('captain.returns.index'))->assertOk()->assertSee($ref);
        $this->get(route('captain.orders.show', $o))->assertOk()->assertSee('إرجاع الطرد');
        $this->get(route('captain.orders.index'))->assertOk()->assertDontSee($ref);
        $this->asStaff($this->captain)->patch(route('captain.orders.status', $o), ['status' => 'return_picked_up'])->assertForbidden();
        $w = app(OrderWorkflow::class);
        foreach (['return_picked_up', 'return_transit'] as $s) {
            $o = $w->transition($other, $o, $s);
        }
        $o = $w->transition($other, $o, 'returned', 'سلمت الطرد لصاحب المتجر');
        $this->assertSame('returned', $o->status);
        $this->assertSame($ref, $o->reference);
        $this->assertDatabaseCount('return_tasks', 1);
        $this->assertDatabaseCount('financial_entries', 2);
        $this->assertSame(1300, (int) FinancialEntry::sum('fee_minor'));
        $this->assertSame(-1300, (int) FinancialEntry::sum('merchant_net_minor'));
        $this->assertSame(0, (int) FinancialEntry::sum('collected_minor'));
        $this->assertSame(100, (int) FinancialEntry::where('captain_id', $other->id)->sum('captain_pay_minor'));
        $this->assertSame(100, (int) $o->entry->captain_pay_minor);
        $this->assertNotNull($o->returnTask->completed_at);
        $this->assertDatabaseCount('loyalty_entries', 0);
        $this->asStaff($this->admin)->get(route('admin.returns.index'))->assertOk()->assertSee($ref);
        $this->get(route('admin.returns.export'))->assertOk()->assertDownload('metjari-returns.csv');
        $this->get(route('admin.reports'))->assertOk();
        $this->asMerchant($this->owner)->get(route('merchant.finance'))->assertOk()->assertViewHas('entries', fn ($entries) => $entries->count() === 1);
        $s = app(Finance::class)->settle($this->admin, $this->owner->store, 'رسوم المرتجع', -1300);
        $this->assertSame(1, FinancialEntry::where('settlement_id', $s->id)->count());
        app(Finance::class)->payCaptain($this->admin, $other, 'أجرة الإرجاع', 100);
    }

    public function test_office_confirmation_and_return_assignment_cannot_be_skipped(): void
    {
        $o = $this->failed();
        $this->asStaff($this->captain)->patch(route('captain.orders.status', $o), ['status' => 'returned', 'note' => 'تجاوز المكتب'])->assertForbidden();
        $this->asStaff($this->admin)->post(route('admin.returns.assign', $o), ['captain_id' => $this->captain->id])->assertSessionHasErrors('captain_id');
        $this->asStaff($this->captain)->patch(route('captain.orders.status', $o), ['status' => 'office_pending', 'note' => 'سلمته للمكتب'])->assertSessionHasNoErrors();
        $this->patch(route('captain.orders.status', $o), ['status' => 'office_received', 'note' => 'تأكيد ذاتي'])->assertForbidden();
        $this->asStaff($this->admin)->patch(route('admin.orders.status', $o), ['status' => 'office_received', 'note' => 'تم الاستلام'])->assertSessionHasNoErrors();
        $this->post(route('admin.returns.assign', $o), ['captain_id' => $this->captain->id])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('orders', ['id' => $o->id, 'status' => 'return_assigned']);
    }

    public function test_reassignment_before_pickup_revokes_old_captain_access(): void
    {
        $o = $this->office();
        $a = $this->staff('captain', '0591000003');
        $b = $this->staff('captain', '0591000004');
        app(Returns::class)->assign($this->admin, $o, $a->id);
        app(Returns::class)->assign($this->admin, $o, $b->id);
        $this->asStaff($a)->get(route('captain.orders.show', $o))->assertNotFound();
        $this->getJson(route('captain.notifications.index'))->assertJsonPath('unread', 0);
        $this->asStaff($b)->getJson(route('captain.notifications.index'))->assertJsonPath('unread', 1);
        $o = app(OrderWorkflow::class)->transition($b, $o, 'return_picked_up');
        $this->asStaff($this->admin)->post(route('admin.returns.assign', $o), ['captain_id' => $a->id])->assertSessionHasErrors('captain_id');
    }

    public function test_same_captain_gets_whole_return_pay_once(): void
    {
        $o = $this->office();
        $w = app(OrderWorkflow::class);
        app(Returns::class)->assign($this->admin, $o, $this->captain->id);
        foreach (['return_picked_up', 'return_transit'] as $s) {
            $o = $w->transition($this->captain, $o, $s);
        }
        $w->transition($this->captain, $o, 'returned', 'استلمه صاحب المتجر');
        $this->assertDatabaseCount('financial_entries', 1);
        $this->assertSame(200, (int) FinancialEntry::sum('captain_pay_minor'));
        $this->asStaff($this->captain)->patch(route('captain.orders.status', $o), ['status' => 'returned', 'note' => 'ضغط مكرر'])->assertForbidden();
        $this->assertDatabaseCount('financial_entries', 1);
    }

    public function test_rewards_are_reserved_then_approved_once_and_coupon_used_by_owner_only(): void
    {
        $this->enable();
        $this->earn();
        $key = (string) Str::uuid();
        $l = app(Loyalty::class);
        $r = $l->request($this->owner, $key, 10);
        $this->assertSame($r->id, $l->request($this->owner, $key, 10)->id);
        $this->assertSame(0, LoyaltyAccount::first()->available);
        $this->assertDatabaseCount('reward_requests', 1);
        $this->asMerchant($this->owner)->post(route('merchant.points.redeem'), ['submission_key' => (string) Str::uuid(), 'expected_points' => 10])->assertSessionHasErrors('points');
        $this->asStaff($this->admin)->get(route('admin.rewards.index'))->assertOk()->assertSee('10 نقطة');
        $this->get(route('admin.points.account', $this->owner->store->id))->assertOk();
        $this->patch(route('admin.rewards.review', $r), ['decision' => 'approved', 'review_note' => 'تم التحقق من الطلبات'])->assertSessionHasNoErrors();
        $this->patch(route('admin.rewards.review', $r), ['decision' => 'approved', 'review_note' => 'ضغط مكرر'])->assertSessionHasErrors('decision');
        $this->assertDatabaseCount('coupons', 1);
        $this->assertSame(0, (int) LoyaltyAccount::first()->balance);
        $this->assertSame(0, (int) LoyaltyAccount::first()->reserved);
        $coupon = $r->fresh()->coupon;
        $this->assertSame(10000, (int) $coupon->value);
        $this->asMerchant($this->owner)->get(route('merchant.points'))->assertOk()->assertSee($coupon->code);
        $this->get(route('merchant.orders.create', ['coupon_code' => $coupon->code]))->assertOk()->assertSee($coupon->code);
        $o = $this->order(['coupon_code' => $coupon->code]);
        $this->assertSame(0, (int) $o->fee_minor);
        $this->assertSame(600, (int) $o->captain_pay_minor);
        $this->assertSame(0, (int) $o->loyalty_points);
        $this->assertSame(1, CouponRedemption::count());
        $this->asMerchant($this->owner)->post(route('merchant.orders.preview'), $this->data(['coupon_code' => $coupon->code]))->assertSessionHasErrors('coupon_code');
        $another = $this->merchant(true, '0592000002', 'متجر آخر');
        $addr = $this->make(PickupAddress::class, ['store_id' => $another->store->id, 'zone_id' => $this->from->id, 'label' => 'فرع', 'address' => 'عنوان المتجر الآخر', 'contact_phone' => '0591000009', 'active' => true]);
        $this->asMerchant($another)->post(route('merchant.orders.preview'), $this->data(['pickup_address_id' => $addr->id, 'coupon_code' => $coupon->code]))->assertSessionHasErrors('coupon_code');
        app(OrderWorkflow::class)->transition($this->owner, $o, 'cancelled', 'إلغاء قبل الاستلام');
        $this->assertSame(0, CouponRedemption::count());
        $this->assertSame(0, (int) $this->order(['coupon_code' => $coupon->code])->fee_minor);
    }

    public function test_rejection_releases_points_and_records_reason(): void
    {
        $this->enable();
        $this->earn();
        $r = $this->requestReward();
        app(Loyalty::class)->review($this->admin, $r, 'rejected', 'يلزم مراجعة بيانات المتجر');
        $this->assertSame(10, LoyaltyAccount::first()->available);
        $this->assertDatabaseCount('coupons', 0);
        $this->asMerchant($this->owner)->get(route('merchant.points'))->assertOk()->assertSee('يلزم مراجعة');
    }

    public function test_points_are_snapshotted_and_duplicate_sync_does_not_duplicate_award(): void
    {
        $this->enable();
        $o = $this->order();
        $this->enable(['points_per_order' => 9]);
        $w = app(OrderWorkflow::class);
        $o = $w->assign($this->admin, $o, $this->captain->id);
        foreach (['picked_up', 'out_for_delivery'] as $s) {
            $o = $w->transition($this->captain, $o, $s);
        }
        $o = $w->transition($this->captain, $o, 'delivered', null, $o->collect_minor);
        app(Loyalty::class)->sync($o);
        app(Loyalty::class)->sync($o);
        $this->assertSame(2, (int) LoyaltyAccount::first()->balance);
        $this->assertDatabaseCount('loyalty_entries', 1);
    }

    public function test_electronic_transfer_waits_for_finance_and_correction_reverses_points(): void
    {
        $this->enable();
        $w = app(OrderWorkflow::class);
        $o = $w->assign($this->admin, $this->order(), $this->captain->id);
        foreach (['picked_up', 'out_for_delivery'] as $s) {
            $o = $w->transition($this->captain, $o, $s);
        }
        $payment = ['payment_method' => 'palpay', 'payment_recipient' => 'merchant', 'payment_account' => '0591112222', 'payment_reference' => 'PAY-123'];
        $o = $w->transition($this->captain, $o, 'delivered', null, $o->collect_minor, $payment);
        $this->assertDatabaseCount('loyalty_entries', 0);
        $p = $o->payment;
        app(Payments::class)->review($this->admin, $p, 'approved', 'تم التحقق من التحويل');
        $this->assertSame(2, (int) LoyaltyAccount::first()->balance);
        $payment['payment_reference'] = 'PAY-NEW';
        $payment['correction_reason'] = 'تصحيح مرجع التحويل الفعلي';
        app(Payments::class)->correctOrder($this->admin, $o, $payment);
        $this->assertSame(0, (int) LoyaltyAccount::first()->balance);
        app(Payments::class)->review($this->admin, $p->fresh(), 'approved', 'تم التحقق مجددًا');
        $this->assertSame(2, (int) LoyaltyAccount::first()->balance);
        $this->assertSame(3, LoyaltyEntry::count());
    }

    public function test_pause_preserves_issued_coupons_and_pending_request_conditions(): void
    {
        $this->enable();
        $this->earn();
        $r = $this->requestReward();
        $this->enable(['enabled' => false, 'reward_points' => 20, 'coupon_days' => 2]);
        $l = app(Loyalty::class);
        $r = $l->review($this->admin, $r, 'approved', 'وافقنا بالشروط وقت التقديم');
        $this->assertSame(10, (int) $r->points);
        $this->assertSame(30, (int) $r->coupon_days);
        $this->assertSame(0, (int) $this->order(['coupon_code' => $r->coupon->code])->fee_minor);
        $this->assertSame(0, (int) $this->order()->loyalty_points);
        $this->asMerchant($this->owner)->post(route('merchant.points.redeem'), ['submission_key' => (string) Str::uuid(), 'expected_points' => 20])->assertSessionHasErrors('points');
    }

    public function test_other_roles_and_merchants_cannot_read_or_review_someone_elses_rewards(): void
    {
        $this->enable();
        $this->earn();
        $r = $this->requestReward();
        $another = $this->merchant(true, '0592000002');
        $this->asMerchant($another)->getJson(route('merchant.notifications.index'))->assertJsonPath('unread', 0);
        $this->get(route('merchant.points'))->assertOk()->assertViewHas('requests', fn ($requests) => $requests->count() === 0);
        $this->asStaff($this->captain)->patch(route('admin.rewards.review', $r), ['decision' => 'approved', 'review_note' => 'محاولة كابتن'])->assertForbidden();
        $this->get(route('admin.points.settings'))->assertForbidden();
        $operations = $this->staff('operations', '0591000003');
        $this->asStaff($operations)->get(route('admin.rewards.index'))->assertForbidden();
    }

    public function test_settings_and_all_new_pages_render_with_validation(): void
    {
        $this->asStaff($this->admin)->get(route('admin.points.settings'))->assertOk();
        $this->put(route('admin.points.settings.update'), ['enabled' => 1, 'points_per_order' => 2, 'reward_points' => 10, 'coupon_days' => 30, 'include_free_orders' => 0])->assertSessionHasNoErrors();
        $this->put(route('admin.points.settings.update'), ['enabled' => 1, 'points_per_order' => 0, 'reward_points' => 0, 'coupon_days' => 0, 'include_free_orders' => 0])->assertSessionHasErrors(['points_per_order', 'reward_points', 'coupon_days']);
        $this->get(route('admin.rewards.index'))->assertOk();
        $this->get(route('admin.returns.index'))->assertOk();
        $this->asMerchant($this->owner)->get(route('merchant.points'))->assertOk();
        $this->get(route('merchant.dashboard'))->assertOk();
        $this->asStaff($this->captain)->get(route('captain.dashboard'))->assertOk();
        $this->get(route('captain.returns.index'))->assertOk();
    }

    public function test_adjusted_points_block_approval_until_reservations_are_released(): void
    {
        $this->enable();
        $this->earn();
        $r = $this->requestReward();
        $o = Order::where('status', 'delivered')->first();
        app(Payments::class)->correctOrder($this->admin, $o, ['payment_method' => 'palpay', 'payment_recipient' => 'merchant', 'payment_account' => '0591112222', 'payment_reference' => 'CHECK-123', 'correction_reason' => 'تصحيح التحصيل وبانتظار التحقق']);
        $this->assertSame(8, (int) LoyaltyAccount::first()->balance);
        $this->asStaff($this->admin)->patch(route('admin.rewards.review', $r), ['decision' => 'approved', 'review_note' => 'اختبار تغير الرصيد'])->assertSessionHasErrors('decision');
        $this->assertDatabaseCount('coupons', 0);
        app(Loyalty::class)->review($this->admin, $r, 'rejected', 'حررنا الحجز بعد تغير الرصيد');
        $this->assertSame(8, LoyaltyAccount::first()->available);
    }

    public function test_free_order_eligibility_and_old_orders_are_not_credited_retroactively(): void
    {
        $old = $this->order();
        $this->enable();
        app(Loyalty::class)->sync($old);
        $this->assertSame(0, (int) $old->loyalty_points);
        $coupon = $this->make(Coupon::class, ['code' => 'FREE-TEST', 'store_id' => $this->owner->store->id, 'type' => 'percent', 'value' => 10000, 'max_uses' => 10, 'per_store_limit' => 10, 'starts_on' => today()->subDay(), 'ends_on' => today()->addDay(), 'active' => true]);
        $this->assertSame(0, (int) $this->order(['coupon_code' => $coupon->code])->loyalty_points);
        $this->enable(['include_free_orders' => true]);
        $o = $this->delivered(['coupon_code' => $coupon->code]);
        $this->assertSame(2, (int) LoyaltyAccount::first()->balance);
        $this->assertSame(0, (int) $o->fee_minor);
        $this->assertSame(600, (int) $o->captain_pay_minor);
    }

    public function test_expired_reward_cannot_be_used_and_reward_cannot_be_edited_as_generic_coupon(): void
    {
        $this->enable();
        $this->earn();
        $r = $this->requestReward();
        $r = app(Loyalty::class)->review($this->admin, $r, 'approved', 'تم التحقق من استحقاق الهدية');
        $coupon = $r->coupon;
        $this->asStaff($this->admin)->patch(route('admin.catalog.update', ['coupons', $coupon->id]), ['code' => $coupon->code, 'store_id' => $this->owner->store->id, 'type' => 'percent', 'value' => '100.00', 'min_fee_minor' => '0', 'max_uses' => 99, 'per_store_limit' => 99, 'starts_on' => today()->toDateString(), 'ends_on' => today()->addMonth()->toDateString(), 'active' => 1])->assertSessionHasErrors('code');
        $this->assertSame(1, (int) $coupon->fresh()->max_uses);
        $coupon->forceFill(['starts_on' => today()->subDays(2), 'ends_on' => today()->subDay()])->save();
        $this->asMerchant($this->owner)->post(route('merchant.orders.preview'), $this->data(['coupon_code' => $coupon->code]))->assertSessionHasErrors('coupon_code');
    }

    public function test_reward_return_releases_coupon_and_restores_full_return_fee_without_points(): void
    {
        $this->enable();
        $this->earn();
        $r = app(Loyalty::class)->review($this->admin, $this->requestReward(), 'approved', 'تم التأكد من الرصيد');
        $w = app(OrderWorkflow::class);
        $o = $w->assign($this->admin, $this->order(['coupon_code' => $r->coupon->code]), $this->captain->id);
        foreach (['picked_up', 'failed', 'office_pending'] as $status) {
            $o = $w->transition($this->captain, $o, $status, 'تأكيد الإجراء من الكابتن');
        }
        $o = $w->transition($this->admin, $o, 'office_received', 'أكد المكتب استلام الطرد');
        app(Returns::class)->assign($this->admin, $o, $this->captain->id);
        foreach (['return_picked_up', 'return_transit', 'returned'] as $status) {
            $o = $w->transition($this->captain, $o, $status, 'أكد المتجر استلام المرتجع');
        }
        $this->assertSame(1300, (int) $o->entry->fee_minor);
        $this->assertSame(0, (int) LoyaltyAccount::first()->balance);
        $this->assertSame(0, CouponRedemption::where('coupon_id', $r->coupon_id)->count());
        $this->assertSame(0, (int) $this->order(['coupon_code' => $r->coupon->code])->fee_minor);
    }

    public function test_new_coupon_cannot_be_generated_using_stale_threshold_and_inactive_captain_cannot_receive_return(): void
    {
        $this->enable();
        $this->earn();
        $this->enable(['reward_points' => 20]);
        $this->asMerchant($this->owner)->post(route('merchant.points.redeem'), ['submission_key' => (string) Str::uuid(), 'expected_points' => 10])->assertSessionHasErrors('points');
        $this->assertDatabaseCount('reward_requests', 0);
        $o = $this->office();
        $other = $this->staff('captain', '0591000003');
        $other->forceFill(['status' => 'suspended'])->save();
        $this->asStaff($this->admin)->post(route('admin.returns.assign', $o), ['captain_id' => $other->id])->assertSessionHasErrors('captain_id');
    }

    public function test_captain_with_assigned_return_cannot_be_suspended_through_staff_admin(): void
    {
        $o = $this->office();
        $other = $this->staff('captain', '0591000003');
        app(Returns::class)->assign($this->admin, $o, $other->id);
        $this->asStaff($this->admin)->patch(route('admin.staff.suspend', $other), ['reason' => 'اختبار منع تعطيل مهمة مفتوحة'])->assertSessionHasErrors();
        $this->assertTrue($other->fresh()->isActive());
    }
}
