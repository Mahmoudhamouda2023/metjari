<?php

namespace Tests\Feature;

use App\Models\CaptainPayout;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\DeliveryAudit;
use App\Models\DeliveryCredit;
use App\Models\DeliveryNotification;
use App\Models\DeliveryPackage;
use App\Models\Expense;
use App\Models\FinancialEntry;
use App\Models\Order;
use App\Models\OrderIssue;
use App\Models\PickupAddress;
use App\Models\Remittance;
use App\Models\Settlement;
use App\Models\StoreAgreement;
use App\Models\Subscription;
use App\Models\Tariff;
use App\Models\Zone;
use App\Services\Delivery\Finance;
use App\Services\Delivery\FinancialBatches;
use App\Services\Delivery\Money;
use App\Services\Delivery\OrderWorkflow;
use App\Services\Delivery\Payments;
use App\Services\Delivery\Pricing;
use App\Services\Delivery\Returns;
use GazaDelivery\Foundation\Models\Staff;
use GazaDelivery\Foundation\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class DeliveryWorkflowTest extends TestCase
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

    private function coupon(array $extra = []): Coupon
    {
        return $this->make(Coupon::class, array_replace(['code' => 'WELCOME', 'type' => 'percent', 'value' => 2000, 'max_uses' => 2, 'per_store_limit' => 1, 'min_fee_minor' => 0, 'starts_on' => today()->subDay(), 'ends_on' => today()->addDay(), 'active' => true], $extra));
    }

    private function subscription(array $extra = []): Subscription
    {
        $p = $this->make(DeliveryPackage::class, ['name' => 'بداية', 'price_minor' => 5000, 'duration_days' => 30, 'included_deliveries' => 2, 'discount_bps' => 1500, 'active' => true]);

        return $this->make(Subscription::class, array_replace(['store_id' => $this->owner->store->id, 'delivery_package_id' => $p->id, 'package_name' => $p->name, 'price_minor' => $p->price_minor, 'duration_days' => 30, 'included_deliveries' => 2, 'remaining_deliveries' => 2, 'discount_bps' => 1500, 'status' => 'active', 'starts_at' => now()->subMinute(), 'expires_at' => now()->addMonth()], $extra));
    }

    public function test_money_is_exact_and_rounding_is_deterministic(): void
    {
        $this->assertSame(1234, Money::minor('12.34'));
        $this->assertSame(1, Money::minor('0.01'));
        $this->assertSame(120, Money::percent(1000, 1200));
        $this->assertSame(1, Money::percent(5, 1000));
        $this->expectException(ValidationException::class);
        Money::minor('1e5');
    }

    public function test_per_store_discount_and_recipient_fee_payer_are_correct(): void
    {
        $this->make(StoreAgreement::class, ['store_id' => $this->owner->store->id, 'discount_bps' => 1200, 'starts_on' => today(), 'active' => true]);
        $o = $this->order(['fee_payer' => 'recipient']);
        $this->assertSame(880, (int) $o->fee_minor);
        $this->assertSame(10880, (int) $o->collect_minor);
        $o2 = $this->order();
        $this->assertSame(10000, (int) $o2->collect_minor);
    }

    public function test_price_snapshot_survives_tariff_edit(): void
    {
        $o = $this->order();
        $this->tariff->forceFill(['fee_minor' => 2000])->save();
        $this->assertSame(1000, (int) $o->fresh()->fee_minor);
        $this->assertSame(2000, (int) $this->order()->fee_minor);
    }

    public function test_duplicate_submission_does_not_duplicate_order_or_credit(): void
    {
        $s = $this->make(DeliveryCredit::class, ['store_id' => $this->owner->store->id, 'quantity' => 2, 'remaining' => 2, 'expires_on' => today()->addDay(), 'reason' => 'هدية', 'granted_by' => $this->admin->id]);
        $d = $this->data();
        $w = app(OrderWorkflow::class);
        $o = $w->create($this->owner, $d);
        $again = $w->create($this->owner, $d);
        $this->assertSame($o->id, $again->id);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, (int) $s->fresh()->remaining);
    }

    public function test_changed_quote_aborts_without_reserving_benefit(): void
    {
        $s = $this->make(DeliveryCredit::class, ['store_id' => $this->owner->store->id, 'quantity' => 2, 'remaining' => 2, 'expires_on' => today()->addDay(), 'reason' => 'هدية', 'granted_by' => $this->admin->id]);
        try {
            $this->order(['expected_fee' => 1000]);
            $this->fail('Expected changed price');
        } catch (ValidationException) {
        }$this->assertSame(0, Order::count());
        $this->assertSame(2, (int) $s->fresh()->remaining);
    }

    public function test_cancelling_refunds_credit_once_and_terminal_status_is_locked(): void
    {
        $s = $this->make(DeliveryCredit::class, ['store_id' => $this->owner->store->id, 'quantity' => 2, 'remaining' => 2, 'expires_on' => today()->addDay(), 'reason' => 'هدية', 'granted_by' => $this->admin->id]);
        $o = $this->order();
        $this->assertSame(0, (int) $o->fee_minor);
        $w = app(OrderWorkflow::class);
        $w->transition($this->owner, $o, 'cancelled', 'إلغاء من المتجر');
        $this->assertSame(2, (int) $s->fresh()->remaining);
        try {
            $w->transition($this->owner, $o, 'cancelled', 'مرة ثانية');
            $this->fail();
        } catch (HttpException) {
        }$this->assertSame(2, (int) $s->fresh()->remaining);
    }

    public function test_gift_is_reserved_before_subscription_and_restored_on_return(): void
    {
        $s = $this->subscription();
        $c = $this->make(DeliveryCredit::class, ['store_id' => $this->owner->store->id, 'quantity' => 1, 'remaining' => 1, 'expires_on' => today()->addDay(), 'reason' => 'هدية ترحيبية', 'granted_by' => $this->admin->id]);
        $o = $this->order();
        $this->assertSame($c->id, $o->delivery_credit_id);
        $this->assertSame(0, (int) $c->fresh()->remaining);
        $this->assertSame(2, (int) $s->fresh()->remaining_deliveries);
        $w = app(OrderWorkflow::class);
        $o = $w->assign($this->admin, $o, $this->captain->id);
        $o = $w->transition($this->captain, $o, 'picked_up');
        $o = $w->transition($this->captain, $o, 'failed', 'المستلم غير متاح');
        $o = $w->transition($this->captain, $o, 'office_pending', 'سلمت الطرد للمكتب');
        $o = $w->transition($this->admin, $o, 'office_received', 'أكد المكتب الاستلام');
        app(Returns::class)->assign($this->admin, $o, $this->captain->id);
        $o = $w->transition($this->captain, $o, 'return_picked_up');
        $o = $w->transition($this->captain, $o, 'return_transit');
        $o = $w->transition($this->captain, $o, 'returned', 'أكد المتجر استلام الطرد');
        $this->assertSame(1, (int) $c->fresh()->remaining);
        $e = $o->entry;
        $this->assertSame(-1300, (int) $e->merchant_net_minor);
        $this->assertSame(0, (int) $e->collected_minor);
        $this->assertSame(200, (int) $e->captain_pay_minor);
    }

    public function test_expired_and_future_benefits_do_not_apply(): void
    {
        $this->subscription(['starts_at' => now()->addDay(), 'expires_at' => now()->addMonth()]);
        $this->make(StoreAgreement::class, ['store_id' => $this->owner->store->id, 'discount_bps' => 1200, 'starts_on' => today()->subDays(3), 'ends_on' => today()->subDay(), 'active' => true]);
        $this->assertSame(1000, (int) $this->order()->fee_minor);
    }

    public function test_coupon_limited_and_cancellation_releases_use(): void
    {
        $c = $this->coupon();
        $o = $this->order(['coupon_code' => 'welcome']);
        $this->assertSame(800, (int) $o->fee_minor);
        $this->assertSame(1, CouponRedemption::count());
        try {
            $this->order(['coupon_code' => 'WELCOME']);
            $this->fail();
        } catch (ValidationException) {
        }
        app(OrderWorkflow::class)->transition($this->owner, $o, 'cancelled', 'إلغاء التجربة');
        $this->order(['coupon_code' => 'WELCOME']);
        $this->assertSame(1, CouponRedemption::count());
    }

    public function test_fixed_coupon_is_capped_and_best_discount_is_not_stacked(): void
    {
        $this->coupon(['type' => 'fixed', 'value' => 5000, 'max_discount_minor' => 400]);
        $o = $this->order(['coupon_code' => 'WELCOME']);
        $this->assertSame(600, (int) $o->fee_minor);
    }

    public function test_coupon_for_another_store_cannot_be_used(): void
    {
        $other = $this->merchant(true, '0592000002');
        $this->coupon(['store_id' => $other->store->id]);
        $this->expectException(ValidationException::class);
        $this->order(['coupon_code' => 'WELCOME']);
    }

    public function test_orders_cannot_use_another_merchants_address(): void
    {
        $other = $this->merchant(true, '0592000002');
        $this->address->forceFill(['store_id' => $other->store->id])->save();
        $this->expectException(ValidationException::class);
        $this->order();
    }

    public function test_inactive_routes_fail_without_creating_orders(): void
    {
        $this->tariff->forceFill(['active' => false])->save();
        $this->expectException(ValidationException::class);
        $this->order();
    }

    public function test_full_cod_lifecycle_has_one_ledger_entry_and_no_settlement_before_cash(): void
    {
        $o = $this->delivered();
        $this->assertSame(1, FinancialEntry::count());
        $this->assertSame(9000, (int) $o->entry->merchant_net_minor);
        $f = app(Finance::class);
        try {
            $f->settle($this->admin, $this->owner->store, 'دفع مبكر', 9000);
            $this->fail();
        } catch (ValidationException) {
        }
        $r = $f->requestRemittance($this->captain);
        $this->assertSame(10000, (int) $r->amount_minor);
        $f->reviewRemittance($this->admin, $r, true, 'إيصال نقدي ١', 10000);
        $s = $f->settle($this->admin, $this->owner->store, 'دفع للتاجر ١', 9000);
        $this->assertSame(9000, (int) $s->amount_minor);
        $p = $f->payCaptain($this->admin, $this->captain, 'دفعة الكابتن ١', 600);
        $this->assertSame(600, (int) $p->amount_minor);
        $this->assertSame($s->id, $o->entry->fresh()->settlement_id);
        try {
            $f->settle($this->admin, $this->owner->store, 'تكرار', 9000);
            $this->fail();
        } catch (ValidationException) {
        }$this->assertSame(1, Settlement::count());
    }

    public function test_recipient_paid_fee_keeps_full_goods_value_for_merchant(): void
    {
        $o = $this->delivered(['fee_payer' => 'recipient']);
        $this->assertSame(11000, (int) $o->entry->collected_minor);
        $this->assertSame(10000, (int) $o->entry->merchant_net_minor);
    }

    public function test_wrong_collection_does_not_mark_delivered(): void
    {
        $w = app(OrderWorkflow::class);
        $o = $w->assign($this->admin, $this->order(), $this->captain->id);
        $o = $w->transition($this->captain, $o, 'picked_up');
        $o = $w->transition($this->captain, $o, 'out_for_delivery');
        try {
            $w->transition($this->captain, $o, 'delivered', null, 9500);
            $this->fail();
        } catch (ValidationException) {
        }$this->assertSame('out_for_delivery', $o->fresh()->status);
        $this->assertSame(0, FinancialEntry::count());
    }

    public function test_rejecting_remittance_releases_entries_and_approval_cannot_repeat(): void
    {
        $this->delivered();
        $f = app(Finance::class);
        $r = $f->requestRemittance($this->captain);
        $f->reviewRemittance($this->admin, $r, false, 'مبلغ لم يصل', null);
        $this->assertNull(FinancialEntry::first()->remittance_id);
        $new = $f->requestRemittance($this->captain);
        try {
            $f->reviewRemittance($this->admin, $new, true, 'ناقص', 9999);
            $this->fail();
        } catch (ValidationException) {
        }$this->assertSame('pending', $new->fresh()->status);
        $f->reviewRemittance($this->admin, $new, true, 'استلام كامل', 10000);
        $this->expectException(ValidationException::class);
        $f->reviewRemittance($this->admin, $new, true, 'تكرار', 10000);
    }

    public function test_unassigned_captain_cannot_read_or_change_order(): void
    {
        $other = $this->staff('captain', '0591000003');
        $o = app(OrderWorkflow::class)->assign($this->admin, $this->order(), $this->captain->id);
        $this->asStaff($other)->get(route('captain.orders.show', $o))->assertNotFound();
        $this->patch(route('captain.orders.status', $o), ['status' => 'picked_up'])->assertNotFound();
    }

    public function test_other_merchant_cannot_read_orders_receipts_or_addresses(): void
    {
        $o = $this->order();
        $other = $this->merchant(true, '0592000002');
        $this->asMerchant($other)->get(route('merchant.orders.show', $o))->assertNotFound();
        $this->patch(route('merchant.addresses.toggle', $this->address->id))->assertNotFound();
    }

    public function test_captain_cannot_confirm_return_or_cancel(): void
    {
        $o = app(OrderWorkflow::class)->assign($this->admin, $this->order(), $this->captain->id);
        $this->asStaff($this->captain)->patch(route('captain.orders.status', $o), ['status' => 'cancelled', 'note' => 'إلغاء'])->assertForbidden();
        $this->patch(route('captain.orders.status', $o), ['status' => 'returned', 'note' => 'إعادة'])->assertForbidden();
    }

    public function test_finance_can_view_but_cannot_dispatch_and_operations_cannot_pay(): void
    {
        $finance = $this->staff('finance', '0591000003');
        $ops = $this->staff('operations', '0591000004');
        $o = $this->order();
        $this->asStaff($finance)->get(route('admin.orders.show', $o))->assertOk();
        $this->post(route('admin.orders.assign', $o), ['captain_id' => $this->captain->id])->assertForbidden();
        $this->asStaff($ops)->get(route('admin.finance'))->assertForbidden();
        $this->post(route('admin.gifts.store'), [])->assertForbidden();
    }

    public function test_public_tracking_never_exposes_phone_address_cash_or_internal_notes(): void
    {
        $o = $this->order(['notes' => 'تعليمات داخلية سرية']);
        $this->get(route('tracking', $o->tracking_token))->assertOk()->assertSee('جديد')->assertDontSee($o->recipient_phone)->assertDontSee($o->recipient_address)->assertDontSee('تعليمات داخلية سرية')->assertDontSee('100.00');
    }

    public function test_all_operational_pages_render_with_real_data(): void
    {
        $o = $this->delivered();
        $this->subscription();
        $this->coupon();
        foreach (['zones', 'tariffs', 'agreements', 'coupons'] as $kind) {
            $this->asStaff($this->admin)->get(route('admin.catalog.index', $kind))->assertOk();
        }
        foreach (['admin.orders.index', 'admin.finance', 'admin.subscriptions', 'admin.issues', 'admin.audit'] as $route) {
            $this->get(route($route))->assertOk();
        }
        foreach (['merchant.orders.index', 'merchant.orders.create', 'merchant.addresses.index', 'merchant.benefits.index', 'merchant.finance'] as $route) {
            $this->asMerchant($this->owner)->get(route($route))->assertOk();
        }
        $this->asStaff($this->captain)->get(route('captain.orders.show', $o))->assertOk();
        $this->get(route('captain.finance'))->assertOk();
    }

    public function test_merchant_can_preview_confirm_and_submit_again_safely_over_http(): void
    {
        $d = $this->data();
        $hash = Pricing::fingerprint(app(Pricing::class)->quote($this->owner->store, $d));
        $this->asMerchant($this->owner)->post(route('merchant.orders.preview'), $d)->assertOk()->assertSee('تأكيد وإنشاء الطلب');
        $this->post(route('merchant.orders.store'), $d + ['expected_fee' => 1000, 'quote_hash' => $hash])->assertRedirect();
        $this->post(route('merchant.orders.store'), $d + ['expected_fee' => 1000, 'quote_hash' => $hash])->assertRedirect();
        $this->assertSame(1, Order::count());
    }

    public function test_gifts_can_be_granted_and_removed_package_routes_do_not_exist(): void
    {
        $this->asStaff($this->admin)->post(route('admin.gifts.store'), ['store_id' => $this->owner->store->id, 'quantity' => 2, 'expires_on' => today()->addMonth()->toDateString(), 'reason' => 'هدية ترحيبية'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, (int) DeliveryCredit::first()->remaining);
        $this->get('/admin/catalog/packages')->assertNotFound();
        $this->assertFalse(Route::has('merchant.benefits.request'));
    }

    public function test_catalog_saves_percentage_as_basis_points_and_rejects_above_100(): void
    {
        $d = ['store_id' => $this->owner->store->id, 'discount_bps' => '12.00', 'starts_on' => today()->toDateString(), 'active' => '1'];
        $this->asStaff($this->admin)->post(route('admin.catalog.store', 'agreements'), $d)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1200, (int) StoreAgreement::first()->discount_bps);
        $this->patch(route('admin.catalog.update', ['kind' => 'agreements', 'id' => StoreAgreement::first()->id]), array_replace($d, ['discount_bps' => '101']))->assertSessionHasErrors('discount_bps');
    }

    public function test_export_escapes_spreadsheet_formulas(): void
    {
        $this->order(['merchant_reference' => '=HYPERLINK("bad")']);
        $r = $this->asMerchant($this->owner)->get(route('merchant.orders.export'));
        $r->assertOk();
        $this->assertStringContainsString("'=HYPERLINK", $r->streamedContent());
    }

    public function test_last_admin_cannot_be_demoted_and_team_accounts_can_be_reactivated(): void
    {
        $d = ['name' => $this->admin->name, 'phone' => $this->admin->phone, 'roles' => ['operations'], 'status' => 'active', 'reason' => 'تعديل الدور'];
        $this->asStaff($this->admin)->patch(route('admin.team.update', $this->admin), $d)->assertSessionHasErrors('roles');
        $this->assertTrue($this->admin->fresh()->hasRole('super_admin'));
        $old = $this->captain->session_version;
        $this->captain->forceFill(['status' => 'suspended'])->save();
        $this->patch(route('admin.team.update', $this->captain), ['name' => 'أحمد', 'phone' => $this->captain->phone, 'roles' => ['captain'], 'vehicle_type' => 'bicycle', 'status' => 'active', 'reason' => 'إعادة تفعيل الحساب'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($this->captain->fresh()->isActive());
        $this->assertSame($old + 1, $this->captain->fresh()->session_version);
        $this->get(route('admin.team.edit', $this->captain))->assertOk();
    }

    public function test_admin_reset_password_invalidates_sessions_and_forces_change_without_logging_password(): void
    {
        $version = $this->owner->session_version;
        $this->asStaff($this->admin)->post(route('admin.stores.reset', $this->owner->store), ['password' => 'ResetTemporary5678', 'password_confirmation' => 'ResetTemporary5678', 'reason' => 'التحقق من هوية المالك'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($this->owner->fresh()->must_change_password);
        $this->assertSame($version + 1, $this->owner->fresh()->session_version);
        $this->assertStringNotContainsString('ResetTemporary5678', json_encode(DeliveryAudit::get()->toArray()));
    }

    public function test_quote_signature_detects_return_fee_change_even_when_delivery_fee_is_same(): void
    {
        $d = $this->data();
        $hash = Pricing::fingerprint(app(Pricing::class)->quote($this->owner->store, $d));
        $this->tariff->forceFill(['return_fee_minor' => 999])->save();
        $this->asMerchant($this->owner)->post(route('merchant.orders.store'), $d + ['quote_hash' => $hash, 'expected_fee' => 1000])->assertSessionHasErrors('price');
        $this->assertSame(0, Order::count());
    }

    public function test_financial_pages_render_approved_batches_and_receipts_are_isolated(): void
    {
        $o = $this->delivered();
        $f = app(Finance::class);
        $r = $f->requestRemittance($this->captain);
        $this->asStaff($this->admin)->get(route('admin.finance.remittances'))->assertOk()->assertSee('كشف #'.$r->id);
        $this->patch(route('admin.finance.remittance', $r->id), ['decision' => 'approved', 'received_amount' => '100', 'reference' => 'استلام من أحمد'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('admin.finance'))->assertOk()->assertSee('تسويات المتاجر الجاهزة');
        $this->post(route('admin.finance.settle', $this->owner->store), ['reference' => 'دفع للمتجر', 'expected_minor' => 9000, 'entry_hash' => Finance::fingerprint([$o->entry->id])])->assertRedirect()->assertSessionHasNoErrors();
        $s = Settlement::firstOrFail();
        $this->get(route('admin.finance.receipt', ['kind' => 'settlement', 'id' => $s->id]))->assertOk()->assertSee($o->reference);
        $this->post(route('admin.finance.payout', $this->captain), ['reference' => 'أجرة أحمد', 'expected_minor' => 600, 'entry_hash' => Finance::fingerprint([$o->entry->id])])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('admin.finance.receipt', ['kind' => 'captain', 'id' => CaptainPayout::first()->id]))->assertOk();
        $this->post(route('admin.finance.expense'), ['submission_key' => (string) Str::uuid(), 'spent_on' => today()->toDateString(), 'category' => 'مساحة العمل', 'description' => 'إيجار يوم', 'amount' => '2.50', 'reference' => 'إيصال ٢'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get(route('admin.reports'))->assertOk()->assertSee('2.50');
        $this->assertSame(250, (int) Expense::first()->amount_minor);
        $this->asMerchant($this->owner)->get(route('merchant.finance.receipt', ['kind' => 'settlement', 'id' => $s->id]))->assertOk();
        $other = $this->merchant(true, '0592000003');
        $this->asMerchant($other)->get(route('merchant.finance.receipt', ['kind' => 'settlement', 'id' => $s->id]))->assertNotFound();
    }

    public function test_order_issue_can_be_opened_and_answered_once(): void
    {
        $o = $this->order();
        $this->asMerchant($this->owner)->post(route('merchant.orders.issue', $o), ['message' => 'يرجى الاتصال قبل التوصيل'])->assertRedirect();
        $issue = OrderIssue::firstOrFail();
        $this->asStaff($this->admin)->patch(route('admin.issues.resolve', $issue->id), ['reply' => 'تم إبلاغ الكابتن وسيتواصل قبل الوصول'])->assertRedirect();
        $this->asMerchant($this->owner)->get(route('merchant.orders.show', $o))->assertOk()->assertSee('تم إبلاغ الكابتن');
    }

    public function test_to_date_filter_works_without_from_date_and_csv_has_no_cross_store_data(): void
    {
        $o = $this->order();
        $this->asMerchant($this->owner)->get(route('merchant.orders.index', ['to' => today()->toDateString()]))->assertOk();
        $other = $this->merchant(true, '0592000004');
        $r = $this->asMerchant($other)->get(route('merchant.orders.export'));
        $r->assertOk();
        $this->assertStringNotContainsString($o->public_id, $r->streamedContent());
    }

    public function test_old_financial_form_cannot_settle_different_orders_with_identical_total(): void
    {
        $first = $this->delivered();
        $f = app(Finance::class);
        $r = $f->requestRemittance($this->captain);
        $f->reviewRemittance($this->admin, $r, true, 'توريد أول', 10000);
        $hash = Finance::fingerprint([$first->entry->id]);
        $f->settle($this->admin, $this->owner->store, 'دفعة أولى', 9000, $hash);
        $second = $this->delivered();
        $r2 = $f->requestRemittance($this->captain);
        $f->reviewRemittance($this->admin, $r2, true, 'توريد ثانٍ', 10000);
        try {
            $f->settle($this->admin, $this->owner->store, 'إعادة إرسال قديمة', 9000, $hash);
            $this->fail();
        } catch (ValidationException) {
        }
        $this->assertSame(1, Settlement::count());
        $this->assertNull($second->entry->fresh()->settlement_id);
    }

    private function electronic(array $extra = []): Order
    {
        $w = app(OrderWorkflow::class);
        $o = $w->assign($this->admin, $this->order(), $this->captain->id);
        $o = $w->transition($this->captain, $o, 'picked_up');
        $o = $w->transition($this->captain, $o, 'out_for_delivery');

        return $w->transition($this->captain, $o, 'delivered', null, 10000, array_replace(['payment_method' => 'bank', 'payment_recipient' => 'merchant', 'payment_account' => 'BANK123456', 'payment_reference' => 'REF-'.Str::uuid()], $extra));
    }

    public function test_remittance_notification_contains_a_link_to_its_receipt(): void
    {
        $o = $this->delivered();
        $batch = app(Finance::class)->requestRemittance($this->captain);
        $notification = DeliveryNotification::where('staff_id', $this->admin->id)
            ->whereNull('order_id')->latest('id')->firstOrFail();
        $this->assertStringContainsString($o->reference, $notification->title);
        $this->assertSame(route('admin.finance.receipt', ['kind' => 'remittance', 'id' => $batch->id]), $notification->target_url);
    }

    public function test_remittance_notification_is_visible_to_admin_and_not_to_captain(): void
    {
        $order = $this->delivered();
        $batch = app(Finance::class)->requestRemittance($this->captain);
        $receiptUrl = route('admin.finance.receipt', ['kind' => 'remittance', 'id' => $batch->id]);

        $adminResponse = $this->asStaff($this->admin)
            ->get(route('admin.notifications.index'))->assertOk();
        $items = collect($adminResponse->json('items'));
        $notice = $items->firstWhere('url', $receiptUrl);
        $this->assertNotNull($notice, 'Admin must see the new remittance notice in the notification center.');
        $this->assertStringContainsString($order->reference, $notice['title']);
        $this->assertGreaterThanOrEqual(1, $adminResponse->json('unread'));

        $this->asStaff($this->captain)
            ->get(route('captain.notifications.index'))->assertOk()
            ->assertJsonMissing(['url' => $receiptUrl]);

        $this->asStaff($this->admin)
            ->postJson(route('admin.notifications.read'), ['through' => $notice['id']])->assertOk();
        $this->get(route('admin.notifications.index'))->assertOk()
            ->assertJsonMissing(['id' => $notice['id'], 'title' => $notice['title'], 'url' => $receiptUrl]);
    }

    public function test_reference_search_and_descending_tasks(): void
    {
        $one = $this->order();
        $two = $this->order();
        $this->assertSame($this->owner->store->fresh()->reference_code.'-'.$one->created_at->timezone('Asia/Hebron')->format('dmY').'-'.str_pad((string) $one->id, 6, '0', STR_PAD_LEFT), $one->reference);
        $w = app(OrderWorkflow::class);
        $w->assign($this->admin, $one, $this->captain->id);
        $w->assign($this->admin, $two, $this->captain->id);
        $this->asStaff($this->admin)->get(route('admin.orders.index', ['q' => $this->owner->store->name]))->assertOk()->assertViewHas('orders', fn ($orders) => $orders->pluck('id')->all() === [$two->id, $one->id]);
        $this->asStaff($this->captain)->get(route('captain.dashboard'))->assertOk()->assertViewHas('tasks', fn ($tasks) => $tasks->pluck('id')->all() === [$two->id, $one->id]);
    }

    public function test_merchant_and_admin_cannot_perform_captain_confirmations(): void
    {
        $w = app(OrderWorkflow::class);
        $o = $w->assign($this->admin, $this->order(), $this->captain->id);
        $this->asMerchant($this->owner)->get(route('merchant.orders.show', $o))->assertOk()->assertDontSee('value="picked_up"', false)->assertSee('value="cancelled"', false);
    }

    public function test_operational_permissions_are_enforced_for_forged_requests(): void
    {
        $w = app(OrderWorkflow::class);
        $o = $w->assign($this->admin, $this->order(), $this->captain->id);
        foreach (['picked_up', 'out_for_delivery', 'delivered'] as $status) {
            $this->asMerchant($this->owner)->patch(route('merchant.orders.status', $o), ['status' => $status, 'collected_amount' => '100'])->assertForbidden();
            $this->asStaff($this->admin)->patch(route('admin.orders.status', $o), ['status' => $status, 'collected_amount' => '100'])->assertForbidden();
        }
        $this->asStaff($this->captain)->patch(route('captain.orders.status', $o), ['status' => 'picked_up'])->assertRedirect()->assertSessionHasNoErrors();
        $this->asMerchant($this->owner)->get(route('merchant.orders.show', $o))->assertOk()->assertDontSee('id="next-status"', false);
    }

    public function test_notifications_are_scoped_readable_and_not_duplicated_on_retry(): void
    {
        $other = $this->staff('captain', '0591000005');
        $data = $this->data();
        $w = app(OrderWorkflow::class);
        $o = $w->create($this->owner, $data);
        $w->create($this->owner, $data);
        $this->asStaff($this->admin)->get(route('admin.notifications.index'))->assertOk()->assertJsonPath('unread', 1);
        $this->asStaff($other)->get(route('captain.notifications.index'))->assertOk()->assertJsonPath('unread', 0);
        $o = $w->assign($this->admin, $o, $this->captain->id);
        $w->assign($this->admin, $o, $this->captain->id);
        $response = $this->asStaff($this->captain)->get(route('captain.notifications.index'))->assertOk()->assertJsonPath('unread', 1);
        $this->postJson(route('captain.notifications.read'), ['through' => $response->json('latest')])->assertOk();
        $this->get(route('captain.notifications.index'))->assertJsonPath('unread', 0);
        $w->assign($this->admin, $o, $other->id);
        $this->get(route('captain.notifications.index'))->assertJsonCount(0, 'items');
        $this->asMerchant($this->owner)->get(route('admin.notifications.index'))->assertForbidden();
    }

    public function test_expense_duplicate_submission_is_recorded_once(): void
    {
        $data = ['submission_key' => (string) Str::uuid(), 'spent_on' => today()->toDateString(), 'category' => 'إيجار', 'description' => 'إيجار يوم', 'amount' => '100', 'reference' => 'EXP1'];
        $this->asStaff($this->admin)->post(route('admin.finance.expense'), $data)->assertSessionHasNoErrors();
        $this->post(route('admin.finance.expense'), $data)->assertSessionHasNoErrors();
        $this->assertSame(1, Expense::count());
        $this->assertSame(10000, (int) Expense::sum('amount_minor'));
    }

    public function test_rejected_remittance_keeps_immutable_details(): void
    {
        $o = $this->delivered();
        $f = app(Finance::class);
        $r = $f->requestRemittance($this->captain);
        $f->reviewRemittance($this->admin, $r, false, 'لم يصل', null);
        $this->assertNull($o->entry->fresh()->remittance_id);
        $rows = FinancialBatches::entries('remittance', $r->id);
        $this->assertCount(1, $rows);
        $this->assertSame(10000, (int) $rows->first()->collected_minor);
        $this->asStaff($this->admin)->get(route('admin.finance.receipt', ['kind' => 'remittance', 'id' => $r->id]))->assertOk()->assertSee($o->reference);
    }

    public function test_direct_payment_is_not_captain_custody_and_received_report_is_isolated(): void
    {
        $o = $this->electronic();
        $p = $o->payment;
        app(Payments::class)->review($this->admin, $p, 'approved', 'تم التحقق');
        $this->asStaff($this->admin)->get(route('admin.reports'))->assertOk()->assertViewHas('byCaptain', fn ($rows) => (int) $rows->first()->cash === 0 && (int) $rows->first()->direct === 10000);
        $this->asMerchant($this->owner)->get(route('merchant.received'))->assertOk()->assertViewHas('directReceived', 10000)->assertViewHas('officeReceived', 0)->assertSee($o->reference);
        $other = $this->merchant(true, '0592000009', 'متجر آخر');
        $this->asMerchant($other)->get(route('merchant.received'))->assertOk()->assertViewHas('directReceived', 0)->assertDontSee($o->reference);
    }

    public function test_finance_can_correct_rejected_transfer_to_cash_and_reverify_electronic(): void
    {
        $o = $this->electronic();
        $payments = app(Payments::class);
        $payments->review($this->admin, $o->payment, 'rejected', 'الحساب خطأ');
        $data = ['payment_method' => 'cash', 'payment_recipient' => 'captain', 'correction_reason' => 'تم التأكد من استلام النقد'];
        $this->asStaff($this->captain)->patch(route('captain.payments.correct', $o->payment), $data)->assertSessionHasErrors('payment_method');
        $this->asStaff($this->admin)->get(route('admin.orders.payment.edit', $o))->assertOk();
        $this->patch(route('admin.orders.payment.correct', $o), $data)->assertSessionHasNoErrors();
        $e = $o->entry->fresh();
        $this->assertSame(10000, (int) $e->collected_minor);
        $this->assertSame(0, (int) $e->merchant_received_minor);
        $this->assertSame(9000, (int) $e->merchant_net_minor);
        $this->assertSame('approved', $o->payment->fresh()->status);
        $this->assertSame(1, DeliveryAudit::where('event', 'payment.corrected')->count());
    }

    public function test_reversal_requires_refund_confirmation_and_preserves_receipts(): void
    {
        $o = $this->delivered();
        $f = app(Finance::class);
        $r = $f->requestRemittance($this->captain);
        $f->reviewRemittance($this->admin, $r, true, 'استلام', 10000);
        $s = $f->settle($this->admin, $this->owner->store, 'دفع', 9000);
        $url = route('admin.finance.reverse', ['kind' => 'settlement', 'id' => $s->id]);
        $this->asStaff($this->admin)->post($url, ['reason' => 'تسجيل بالخطأ'])->assertSessionHasErrors('funds_returned');
        $this->post(route('admin.finance.reverse', ['kind' => 'remittance', 'id' => $r->id]), ['reason' => 'توريد خطأ', 'funds_returned' => 1])->assertSessionHasErrors('reason');
        $this->post($url, ['reason' => 'أعاد التاجر المبلغ للمكتب', 'funds_returned' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull($s->fresh()->voided_at);
        $this->assertNull($o->entry->fresh()->settlement_id);
        $this->asMerchant($this->owner)->get(route('merchant.received'))->assertOk()->assertViewHas('officeReceived', 0);
        $this->get(route('merchant.finance.receipt', ['kind' => 'settlement', 'id' => $s->id]))->assertOk()->assertSee($o->reference)->assertSee('تم عكس');
        $this->asStaff($this->admin)->post($url, ['reason' => 'تكرار العكس', 'funds_returned' => 1])->assertSessionHasErrors('reason');
    }

    public function test_both_staff_suspend_paths_block_open_jobs(): void
    {
        app(OrderWorkflow::class)->assign($this->admin, $this->order(), $this->captain->id);
        $this->asStaff($this->admin)->patch(route('admin.staff.suspend', $this->captain), ['reason' => 'إيقاف تجريبي'])->assertSessionHasErrors('staff');
        $this->assertTrue($this->captain->fresh()->isActive());
    }

    public function test_large_order_collection_and_large_remittance_are_supported(): void
    {
        $o = $this->delivered(['goods_amount' => '9999999.99', 'fee_payer' => 'recipient']);
        $this->assertSame(1000000999, (int) $o->collect_minor);
        $r = app(Finance::class)->requestRemittance($this->captain);
        $this->asStaff($this->admin)->patch(route('admin.finance.remittance', $r), ['decision' => 'approved', 'received_amount' => '10000009.99', 'reference' => 'استلام كبير'])->assertSessionHasNoErrors();
    }

    public function test_existing_rows_are_backfilled_without_changing_agreed_financial_values(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('This legacy DDL rollback test requires transactional SQLite schema changes. MySQL/MariaDB upgrades are verified separately on a disposable database.');
        }
        $o = $this->delivered();
        $f = app(Finance::class);
        $r = $f->requestRemittance($this->captain);
        $f->reviewRemittance($this->admin, $r, true, 'توريد قديم', 10000);
        $settlement = $f->settle($this->admin, $this->owner->store, 'دفعة قديمة', 9000);
        $migration = require database_path('migrations/2026_11_01_000007_strengthen_finance_and_notifications.php');
        $oldOrder = $o->fresh()->getAttributes();
        unset($oldOrder['display_reference'],$oldOrder['return_policy_v2']);
        $oldRemittance = $r->fresh()->getAttributes();
        unset($oldRemittance['voided_at'],$oldRemittance['voided_by'],$oldRemittance['void_reason']);
        $oldSettlement = $settlement->getAttributes();
        unset($oldSettlement['voided_at'],$oldSettlement['voided_by'],$oldSettlement['void_reason']);
        $oldEntry = $o->entry->fresh()->getAttributes();
        foreach (['financial_batch_items', 'financial_entries', 'settlements', 'remittances', 'delivery_notifications', 'order_events', 'orders'] as $table) {
            DB::table($table)->delete();
        }
        $migration->down();
        foreach (['orders' => $oldOrder, 'remittances' => $oldRemittance, 'settlements' => $oldSettlement, 'financial_entries' => $oldEntry] as $table => $row) {
            DB::table($table)->insert($row);
        }
        $migration->up();
        $this->assertSame($this->owner->store->name.' #'.$o->id, $o->fresh()->reference);
        $this->assertFalse($o->fresh()->return_policy_v2);
        $this->assertSame(9000, (int) $o->entry->fresh()->merchant_net_minor);
        $this->assertCount(1, FinancialBatches::entries('settlement', $settlement->id));
    }

    public function test_full_reversal_sequence_unlocks_correction_and_old_snapshot_stays_unchanged(): void
    {
        $o = $this->delivered();
        $f = app(Finance::class);
        $b = app(FinancialBatches::class);
        $r = $f->requestRemittance($this->captain);
        $f->reviewRemittance($this->admin, $r, true, 'استلام', 10000);
        $s = $f->settle($this->admin, $this->owner->store, 'دفع للمتجر', 9000);
        $p = $f->payCaptain($this->admin, $this->captain, 'أجرة', 600);
        $correction = ['payment_method' => 'bank', 'payment_recipient' => 'merchant', 'payment_account' => 'BANK001', 'payment_reference' => 'DIRECT001', 'correction_reason' => 'وصل المبلغ للتاجر مباشرة'];
        $this->asStaff($this->admin)->patch(route('admin.orders.payment.correct', $o), $correction)->assertSessionHasErrors('payment_method');
        $b->reverse($this->admin, 'settlement', $s->id, 'أعاد التاجر كامل المبلغ');
        $b->reverse($this->admin, 'captain', $p->id, 'أعاد الكابتن كامل الأجرة');
        $b->reverse($this->admin, 'remittance', $r->id, 'أعاد المكتب كامل التوريد للكابتن');
        $this->patch(route('admin.orders.payment.correct', $o), $correction)->assertSessionHasNoErrors();
        $this->assertSame('pending', $o->payment()->firstOrFail()->status);
        $this->assertSame(0, (int) $o->entry->fresh()->collected_minor);
        $this->assertSame(-1000, (int) $o->entry->fresh()->merchant_net_minor);
        $this->assertSame(10000, (int) FinancialBatches::entries('remittance', $r->id)->first()->collected_minor);
        $this->assertSame(9000, (int) FinancialBatches::entries('settlement', $s->id)->first()->merchant_net_minor);
        $this->asStaff($this->captain)->get(route('captain.finance'))->assertOk()->assertViewHas('paid', 0);
    }

    public function test_percent_captain_pay_does_not_disappear_when_office_grants_free_delivery(): void
    {
        $this->tariff->forceFill(['captain_pay_mode' => 'percent', 'captain_share_bps' => 7000])->save();
        $this->make(DeliveryCredit::class, ['store_id' => $this->owner->store->id, 'quantity' => 1, 'remaining' => 1, 'expires_on' => today()->addDay(), 'reason' => 'هدية', 'granted_by' => $this->admin->id]);
        $o = $this->delivered();
        $this->assertSame(0, (int) $o->fee_minor);
        $this->assertSame(700, (int) $o->entry->captain_pay_minor);
    }

    public function test_zero_settlement_can_close_without_creating_a_cash_payment(): void
    {
        $o = $this->delivered(['goods_amount' => '10']);
        $f = app(Finance::class);
        $r = $f->requestRemittance($this->captain);
        $f->reviewRemittance($this->admin, $r, true, 'استلام', 1000);
        $this->asStaff($this->admin)->get(route('admin.finance.stores'))->assertOk()->assertSee('إغلاق الحساب دون حركة نقدية');
        $s = $f->settle($this->admin, $this->owner->store, 'إغلاق حساب متعادل', 0);
        $this->assertSame(0, (int) $s->amount_minor);
        $this->asMerchant($this->owner)->get(route('merchant.received', ['to' => today()->toDateString()]))->assertOk()->assertViewHas('officeReceived', 0)->assertViewHas('paidToOffice', 0);
    }

    public function test_notification_read_marker_cannot_read_another_staffs_notifications(): void
    {
        $o = $this->order();
        app(OrderWorkflow::class)->assign($this->admin, $o, $this->captain->id);
        $this->asStaff($this->captain)->postJson(route('captain.notifications.read'), ['through' => 99999])->assertOk();
        $this->asStaff($this->admin)->get(route('admin.notifications.index'))->assertJsonPath('unread', 1);
    }


    public function test_delivery_confirmation_issues_immutable_internal_receipts_without_manual_input(): void
    {
        $first = $this->delivered();
        $second = $this->delivered();

        foreach ([$first, $second] as $order) {
            $this->assertMatchesRegularExpression('/^DLV-\d{8}-\d{6,}$/', $order->fresh()->delivery_reference);
            $this->assertSame($order->delivery_reference, $order->fresh()->delivery_reference);
            $this->assertSame($order->created_at->timezone('Asia/Hebron')->format('dmY'),
                substr($order->reference, 4, 8));
            $this->asStaff($this->captain)->get(route('captain.orders.show', $order))->assertOk()->assertSee($order->delivery_reference);
            $this->asMerchant($this->owner)->get(route('merchant.orders.show', $order))->assertOk()->assertSee($order->delivery_reference);
        }
        $this->assertNotSame($first->delivery_reference, $second->delivery_reference);
    }

    public function test_finance_actions_generate_immutable_server_references_not_user_provided_values(): void
    {
        $order = $this->delivered();
        $finance = app(Finance::class);
        $remittance = $finance->requestRemittance($this->captain);
        $this->assertMatchesRegularExpression('/^REM-\d{8}-\d{6,}$/', $remittance->system_reference);
        $this->assertSame($remittance->system_reference, $remittance->reference);

        $finance->reviewRemittance($this->admin, $remittance, true, 'client-supplied-text', 10000);
        $this->assertSame($remittance->system_reference, $remittance->fresh()->system_reference);
        $this->assertSame('approved', $remittance->fresh()->status);
        $this->assertSame('client-supplied-text', $remittance->fresh()->review_note);

        $settlement = $finance->settle($this->admin, $this->owner->store, 'FAKE-MANUAL-REFERENCE', 9000);
        $payout = $finance->payCaptain($this->admin, $this->captain, 'FAKE-MANUAL-REFERENCE', 600);
        $this->assertMatchesRegularExpression('/^STL-\d{8}-\d{6,}$/', $settlement->system_reference);
        $this->assertMatchesRegularExpression('/^PAY-\d{8}-\d{6,}$/', $payout->system_reference);
        $this->assertSame($settlement->system_reference, $settlement->reference);
        $this->assertSame($payout->system_reference, $payout->reference);
        $this->assertNotSame('FAKE-MANUAL-REFERENCE', $settlement->reference);
        $this->assertNotSame('FAKE-MANUAL-REFERENCE', $payout->reference);

        $this->asStaff($this->admin)
            ->get(route('admin.finance.receipt', ['kind' => 'settlement', 'id' => $settlement->id]))
            ->assertOk()->assertSee($settlement->system_reference)->assertSee($order->delivery_reference);
        $this->asStaff($this->admin)->get(route('admin.finance'))
            ->assertOk()->assertDontSee('name="reference" label="مرجع الصرف الفعلي"');
    }

    public function test_notification_polling_does_not_spend_the_captain_remittance_quota(): void
    {
        $order = $this->delivered();
        $this->asStaff($this->captain);
        for ($i = 0; $i < 12; $i++) {
            $this->getJson(route('captain.notifications.index'))->assertOk();
        }

        $this->post(route('captain.remittances.store'))->assertRedirect(route('captain.finance'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('remittances', 1);
        $this->assertSame(10000, (int) Remittance::firstOrFail()->amount_minor);
        $this->assertSame(Remittance::firstOrFail()->id, $order->entry->fresh()->remittance_id);

        // A normal refresh is a GET; replaying the form cannot include the same funds twice.
        $this->get(route('captain.finance'))->assertOk();
        $this->post(route('captain.remittances.store'))->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('remittances', 1);
    }

    public function test_throttled_notification_feed_does_not_block_remittance_creation(): void
    {
        $this->delivered();
        $this->asStaff($this->captain);
        for ($i = 0; $i < 30; $i++) {
            $this->getJson(route('captain.notifications.index'))->assertOk();
        }
        $this->getJson(route('captain.notifications.index'))->assertStatus(429)->assertHeader('Retry-After');
        $this->post(route('captain.remittances.store'))->assertRedirect(route('captain.finance'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('remittances', 1);
    }

    public function test_order_status_requests_do_not_spend_the_remittance_quota(): void
    {
        $order = $this->delivered();
        $this->asStaff($this->captain);
        for ($i = 0; $i < 10; $i++) {
            $this->patch(route('captain.orders.status', $order), ['status' => 'invalid'])->assertSessionHasErrors('status');
        }
        $this->post(route('captain.remittances.store'))->assertRedirect(route('captain.finance'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('remittances', 1);
    }

    public function test_remittance_limit_still_blocks_writes_and_expires_without_affecting_other_captains(): void
    {
        $this->travelTo(now()->startOfMinute());
        $this->asStaff($this->captain);
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('captain.remittances.store'))->assertSessionHasErrors('amount');
        }
        $this->delivered();
        $this->post(route('captain.remittances.store'))->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('remittances', 0);
        $this->get(route('captain.finance'))->assertOk();
        $this->getJson(route('captain.notifications.index'))->assertOk();

        $other = $this->staff('captain', '0591000019');
        $this->asStaff($other)->post(route('captain.remittances.store'))->assertRedirect()->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('remittances', 0);
        $this->travel(61)->seconds();
        $this->asStaff($this->captain)->post(route('captain.remittances.store'))->assertRedirect(route('captain.finance'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('remittances', 1);
        $this->travelBack();
    }

    public function test_notification_limits_separate_staff_and_merchant_with_identical_ids(): void
    {
        if ($this->admin->id !== $this->owner->id) {
            $staff = new Staff;
            $staff->forceFill(['id' => $this->owner->id, 'name' => 'موظف برقم مطابق', 'phone' => '0591000018', 'password' => 'SecureTemporary123!', 'status' => 'active', 'must_change_password' => false])->save();
            $staff->assignRole('super_admin');
            $this->admin = $staff->fresh();
        }
        $this->assertSame($this->admin->id, $this->owner->id);
        $this->asStaff($this->admin);
        for ($i = 0; $i < 30; $i++) {
            $this->getJson(route('admin.notifications.index'))->assertOk();
        }
        $this->getJson(route('admin.notifications.index'))->assertStatus(429);
        $this->asMerchant($this->owner)->getJson(route('merchant.notifications.index'))->assertOk();
    }

    public function test_finance_controller_does_not_require_manual_reference_for_settlement_or_payout(): void
    {
        $order = $this->delivered();
        $finance = app(Finance::class);
        $remittance = $finance->requestRemittance($this->captain);
        $this->asStaff($this->admin)->patch(route('admin.finance.remittance', $remittance), [
            'decision' => 'approved', 'received_amount' => '100.00',
        ])->assertSessionHasNoErrors();
        $entryHash = Finance::fingerprint([$order->entry->id]);
        $this->asStaff($this->admin)->post(route('admin.finance.settle', $this->owner->store), [
            'expected_minor' => 9000, 'entry_hash' => $entryHash,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->asStaff($this->admin)->post(route('admin.finance.payout', $this->captain), [
            'expected_minor' => 600, 'entry_hash' => $entryHash,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Settlement::count());
        $this->assertSame(1, CaptainPayout::count());
    }
}
