<?php

namespace Tests\Feature;

use App\Models\CaptainPayout;
use App\Models\DeliveryCredit;
use App\Models\FinancialEntry;
use App\Models\Order;
use App\Models\PickupAddress;
use App\Models\Remittance;
use App\Models\Settlement;
use App\Models\StoreAgreement;
use App\Models\Tariff;
use App\Models\Zone;
use App\Services\Delivery\Finance;
use App\Services\Delivery\OrderWorkflow;
use App\Services\Delivery\Returns;
use App\Services\Delivery\ServiceFees;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ServiceFeeWorkflowTest extends TestCase
{
    private $owner;
    private $admin;
    private $captain;
    private $returnCaptain;
    private $address;
    private $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->merchant(true);
        $this->admin = $this->staff();
        $this->captain = $this->staff('captain', '0591000002');
        $this->returnCaptain = $this->staff('captain', '0591000003');
        $from = $this->make(Zone::class, ['name' => 'غزة', 'active' => true]);
        $this->to = $this->make(Zone::class, ['name' => 'الشمال', 'active' => true]);
        $this->address = $this->make(PickupAddress::class, ['store_id' => $this->owner->store->id, 'zone_id' => $from->id, 'label' => 'المتجر', 'address' => 'عنوان المتجر التجريبي', 'contact_phone' => '0591000009', 'active' => true]);
        $this->make(Tariff::class, ['from_zone_id' => $from->id, 'to_zone_id' => $this->to->id, 'fee_minor' => 1000, 'captain_pay_minor' => 600, 'return_fee_minor' => 300, 'captain_return_pay_minor' => 200, 'active' => true]);
    }

    private function make(string $model, array $attributes)
    {
        $record = new $model;
        $record->forceFill($attributes)->save();

        return $record->fresh();
    }

    private function order(): Order
    {
        return app(OrderWorkflow::class)->create($this->owner, ['idempotency_key' => (string) Str::uuid(), 'pickup_address_id' => $this->address->id, 'to_zone_id' => $this->to->id, 'recipient_name' => 'المستلم', 'recipient_phone' => '0593334444', 'recipient_address' => 'عنوان المستلم التجريبي', 'description' => 'طرد تجريبي', 'fee_payer' => 'merchant', 'goods_amount' => '100']);
    }

    private function ready(): Order
    {
        $w = app(OrderWorkflow::class);
        $o = $w->assign($this->admin, $this->order(), $this->captain->id);
        $o = $w->transition($this->captain, $o, 'picked_up');

        return $w->transition($this->captain, $o, 'out_for_delivery');
    }

    private function returning(Order $o): Order
    {
        $w = app(OrderWorkflow::class);
        $o = $w->transition($this->captain, $o, 'office_pending', 'سلمت الطرد إلى المكتب');
        $o = $w->transition($this->admin, $o, 'office_received', 'استلم المكتب الطرد');
        app(Returns::class)->assign($this->admin, $o, $this->returnCaptain->id);
        $o = $w->transition($this->returnCaptain, $o, 'return_picked_up');

        return $w->transition($this->returnCaptain, $o, 'return_transit');
    }

    public function test_two_fee_legs_are_collected_remitted_and_paid_to_the_correct_captains(): void
    {
        $w = app(OrderWorkflow::class);
        $o = $this->ready();
        $this->asStaff($this->captain)->patch(route('captain.orders.status', $o), ['status' => 'failed', 'note' => 'المستلم رفض استلام البضاعة', 'collected_amount' => '10'])->assertSessionHasNoErrors();
        $o->refresh();
        $first = $o->financialEntries()->firstOrFail();
        $this->assertSame('failed_delivery', $first->entry_kind);
        $this->assertSame('recipient', $first->service_fee_payer);
        $this->assertSame(1000, (int) $first->collected_minor);
        $this->assertSame(0, (int) $first->merchant_net_minor);
        $this->assertSame(0, FinancialEntry::cleared()->count());
        $f = app(Finance::class);
        $r = $f->requestRemittance($this->captain);
        $this->assertSame(1000, (int) $r->amount_minor);
        $f->reviewRemittance($this->admin, $r, true, '', 1000);
        $f->payCaptain($this->admin, $this->captain, '', 600, Finance::fingerprint([$first->id]));
        $o = $this->returning($o);
        $this->asStaff($this->returnCaptain)->get(route('captain.orders.show', $o))->assertOk()->assertSee('data-goods-due', false)->assertSee('أجرة إرجاع الطرد للمتجر');
        $this->patch(route('captain.orders.status', $o), ['status' => 'returned', 'note' => 'استلم المتجر الطرد ورسومه', 'collected_amount' => '3'])->assertSessionHasNoErrors();
        $second = $o->financialEntries()->where('entry_kind', 'return_delivery')->firstOrFail();
        $this->assertSame('merchant', $second->service_fee_payer);
        $this->assertSame($this->returnCaptain->id, $second->captain_id);
        $r2 = $f->requestRemittance($this->returnCaptain);
        $this->assertSame(300, (int) $r2->amount_minor);
        $f->reviewRemittance($this->admin, $r2, true, '', 300);
        $f->payCaptain($this->admin, $this->returnCaptain, '', 200, Finance::fingerprint([$second->id]));
        $this->assertSame(1300, (int) FinancialEntry::sum('fee_minor'));
        $this->assertSame(800, (int) CaptainPayout::sum('amount_minor'));
        $this->assertSame(10000, (int) $o->fresh()->goods_minor);
        $this->assertDatabaseCount('financial_entries', 2);
        $this->assertDatabaseCount('remittances', 2);
        $this->patch(route('captain.orders.status', $o), ['status' => 'returned', 'note' => 'إعادة ضغط', 'collected_amount' => '3'])->assertForbidden();
        $this->assertDatabaseCount('financial_entries', 2);
    }

    public function test_unpaid_fees_are_not_cash_and_cannot_be_paid_until_collected_and_remitted(): void
    {
        $o = app(OrderWorkflow::class)->transition($this->captain, $this->ready(), 'failed', 'لم يدفع المستلم الرسوم', 0);
        $o = $this->returning($o);
        $o = app(OrderWorkflow::class)->transition($this->returnCaptain, $o, 'returned', 'استلم المتجر دون دفع الرسوم', 0);
        $this->assertSame(0, (int) FinancialEntry::sum('collected_minor'));
        $this->assertSame(0, FinancialEntry::cleared()->count());
        $this->asStaff($this->captain)->post(route('captain.remittances.store'))->assertSessionHasErrors('amount');
        $entry = $o->financialEntries()->where('entry_kind', 'failed_delivery')->firstOrFail();
        $data = ['collected_amount' => '10', 'cash_received' => 1];
        for ($i = 0; $i < 12; $i++) {
            $this->post(route('captain.service-fees.collect', ['order' => $o, 'entry' => $entry->id]), $data)->assertSessionHasNoErrors();
        }
        $this->assertSame(1000, (int) FinancialEntry::sum('collected_minor'));
        $this->assertSame(0, FinancialEntry::cleared()->count());
        $this->post(route('captain.remittances.store'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('remittances', 1);
        $this->assertSame(1000, (int) Remittance::firstOrFail()->amount_minor);
    }

    public function test_goods_or_partial_fee_cannot_be_recorded_as_failed_delivery_cash(): void
    {
        $o = $this->ready();
        $this->asStaff($this->captain)->patch(route('captain.orders.status', $o), ['status' => 'failed', 'note' => 'تعذر التسليم', 'collected_amount' => '100'])->assertSessionHasErrors('collected_amount');
        $this->assertSame('out_for_delivery', $o->fresh()->status);
        $this->assertDatabaseCount('financial_entries', 0);
    }

    public function test_other_captain_cannot_collect_another_leg_and_retry_cannot_rebill_paid_fees(): void
    {
        $w = app(OrderWorkflow::class);
        $o = $w->transition($this->captain, $this->ready(), 'failed', 'تعذر التسليم', 0);
        $entry = $o->financialEntries()->firstOrFail();
        $o = $this->returning($o);
        $this->asStaff($this->returnCaptain)->post(route('captain.service-fees.collect', ['order' => $o, 'entry' => $entry->id]), ['collected_amount' => '10', 'cash_received' => 1])->assertForbidden();
        $this->assertSame(0, (int) $entry->fresh()->collected_minor);
        $another = $w->transition($this->captain, $this->ready(), 'failed', 'تعذر التسليم', 1000);
        $this->asStaff($this->captain)->patch(route('captain.orders.status', $another), ['status' => 'out_for_delivery'])->assertSessionHasErrors('status');
        $this->assertSame('failed', $another->fresh()->status);
    }

    public function test_unpaid_retry_cancels_only_the_uncollected_charge(): void
    {
        $w = app(OrderWorkflow::class);
        $o = $w->transition($this->captain, $this->ready(), 'failed', 'تعذر التسليم', 0);
        $o = $w->transition($this->captain, $o, 'out_for_delivery');
        $this->assertDatabaseCount('financial_entries', 0);
        $o = $w->transition($this->captain, $o, 'delivered', null, 10000);
        $this->assertSame('delivery', $o->entry->entry_kind);
        $this->assertDatabaseCount('financial_entries', 1);
    }

    public function test_store_agreement_is_preserved_before_gift_and_goods_are_zero_in_return_display(): void
    {
        $this->make(StoreAgreement::class, ['store_id' => $this->owner->store->id, 'discount_bps' => 1200, 'starts_on' => today(), 'active' => true]);
        $gift = $this->make(DeliveryCredit::class, ['store_id' => $this->owner->store->id, 'quantity' => 1, 'remaining' => 1, 'expires_on' => today()->addDay(), 'reason' => 'هدية', 'granted_by' => $this->admin->id]);
        $w = app(OrderWorkflow::class);
        $o = $this->ready();
        $this->assertSame(0, (int) $o->fee_minor);
        $this->assertSame(880, (int) $o->agreed_fee_minor);
        $o = $w->transition($this->captain, $o, 'failed', 'تعذر التسليم', 880);
        $o = $this->returning($o);
        $o = $w->transition($this->returnCaptain, $o, 'returned', 'استلم المتجر الطرد', 300);
        $this->assertSame(1180, (int) $o->financialEntries()->sum('fee_minor'));
        $this->assertSame(1, (int) $gift->fresh()->remaining);
        $this->asMerchant($this->owner)->get(route('merchant.orders.show', $o))->assertOk()->assertSee('data-goods-due', false)->assertSee('0.00 ₪');
    }

    public function test_ajax_settlement_updates_balance_and_history_without_another_write(): void
    {
        $w = app(OrderWorkflow::class);
        $o = $w->transition($this->captain, $this->ready(), 'delivered', null, 10000);
        $f = app(Finance::class);
        $r = $f->requestRemittance($this->captain);
        $f->reviewRemittance($this->admin, $r, true, '', 10000);
        $data = ['expected_minor' => 9000, 'entry_hash' => Finance::fingerprint([$o->entry->id])];
        $this->asStaff($this->admin)->postJson(route('admin.finance.settle', $this->owner->store), $data)->assertOk()->assertJsonStructure(['message', 'receipt_url']);
        $this->getJson(route('admin.finance.live'))->assertOk()->assertJsonStructure(['html']);
        $this->get(route('admin.finance'))->assertOk()->assertViewHas('ready', fn ($rows) => $rows->isEmpty());
        $this->postJson(route('admin.finance.settle', $this->owner->store), $data)->assertUnprocessable();
        $this->assertDatabaseCount('settlements', 1);
        \Illuminate\Support\Facades\Auth::guard('staff')->logout();
        $this->asMerchant($this->owner)->getJson(route('admin.finance.live'))->assertUnauthorized();
    }

    public function test_finance_shows_only_five_recent_records_and_filters_the_full_history(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->make(Settlement::class, ['store_id' => $this->owner->store->id, 'amount_minor' => 100 * $i, 'reference' => 'STL-HISTORY-'.$i, 'paid_by' => $this->admin->id]);
        }
        $this->asStaff($this->admin)->get(route('admin.finance'))->assertOk()->assertViewHas('history', fn ($rows) => $rows->count() === 5)->assertSee('عرض جميع التسويات');
        $this->get(route('admin.finance.history', ['kind' => 'settlement']))->assertOk()->assertViewHas('history', fn ($rows) => $rows->total() === 7);
        $this->get(route('admin.finance.history', ['kind' => 'captain']))->assertOk()->assertViewHas('history', fn ($rows) => $rows->total() === 0);
        $this->get(route('admin.finance.history', ['q' => 'STL-HISTORY-2']))->assertOk()->assertViewHas('history', fn ($rows) => $rows->total() === 1);
        $this->asStaff($this->captain)->get(route('admin.finance.history'))->assertForbidden();
    }

    public function test_order_origin_and_reference_category_filters_are_scoped(): void
    {
        $o = $this->ready();
        $this->asStaff($this->admin)->get(route('admin.orders.index', ['origin' => 'merchant']))->assertOk()->assertSee($o->reference);
        $this->get(route('admin.orders.index', ['origin' => 'captain']))->assertOk()->assertDontSee($o->reference);
        $this->get(route('admin.orders.index', ['reference_kind' => 'delivery']))->assertOk()->assertDontSee($o->reference);
    }

    public function test_upgraded_in_flight_return_does_not_invent_prior_collection(): void
    {
        $w = app(OrderWorkflow::class);
        $o = $this->ready();
        $o->forceFill(['service_fee_workflow' => false])->save();
        $o = $w->transition($this->captain, $o, 'failed', 'تعذر قبل الترقية');
        $o = $this->returning($o);
        $o->forceFill(['service_fee_workflow' => true])->save();
        $o = $w->transition($this->returnCaptain, $o, 'returned', 'استلم المتجر بعد الترقية', 300);
        $attempt = $o->financialEntries()->where('entry_kind', 'failed_delivery')->firstOrFail();
        $this->assertNull($attempt->fee_collected_at);
        $this->assertSame(0, (int) $attempt->collected_minor);
        $this->assertSame(300, (int) $o->financialEntries()->sum('collected_minor'));
    }

    public function test_upgrade_preserves_posted_history_and_only_enables_open_unposted_orders(): void
    {
        $open = $this->ready();
        $closed = app(OrderWorkflow::class)->transition($this->captain, $this->ready(), 'delivered', null, 10000);
        $before = $closed->entry->getAttributes();
        $migration = require database_path('migrations/2026_11_05_000013_separate_service_fee_collections.php');
        $migration->down();
        $migration->up();
        $this->assertTrue($open->fresh()->service_fee_workflow);
        $this->assertFalse($closed->fresh()->service_fee_workflow);
        $this->assertSame((int) $before['collected_minor'], (int) $closed->entry->fresh()->collected_minor);
        $this->assertSame((int) $before['merchant_net_minor'], (int) $closed->entry->fresh()->merchant_net_minor);
    }

    public function test_migration_backfills_unpaid_fees_for_an_existing_failed_order(): void
    {
        $o = $this->ready();
        $o->forceFill(['service_fee_workflow' => false])->save();
        $o = app(OrderWorkflow::class)->transition($this->captain, $o, 'failed', 'تعذر قبل التحديث');
        $this->assertDatabaseCount('financial_entries', 0);
        $migration = require database_path('migrations/2026_11_05_000013_separate_service_fee_collections.php');
        $migration->down();
        $migration->up();
        $this->assertTrue($o->fresh()->service_fee_workflow);
        $entry = $o->financialEntries()->firstOrFail();
        $this->assertSame('recipient', $entry->service_fee_payer);
        $this->assertSame(0, (int) $entry->collected_minor);
        $this->assertNull($entry->fee_collected_at);
        $this->assertSame(0, FinancialEntry::cleared()->count());
    }

    public function test_merchant_account_poll_detects_a_new_settlement_without_reload(): void
    {
        $w = app(OrderWorkflow::class);
        $o = $w->transition($this->captain, $this->ready(), 'delivered', null, 10000);
        $f = app(Finance::class);
        $r = $f->requestRemittance($this->captain);
        $f->reviewRemittance($this->admin, $r, true, '', 10000);
        $before = $this->asMerchant($this->owner)->getJson(route('merchant.notifications.index'))->assertOk()->json('version');
        $this->get(route('merchant.finance'))->assertOk()->assertSee('data-live-region="merchant-finance"', false);
        $f->settle($this->admin, $this->owner->store, '', 9000, Finance::fingerprint([$o->entry->id]));
        $after = $this->getJson(route('merchant.notifications.index'))->assertOk()->json('version');
        $this->assertNotSame($before, $after);
        $this->get(route('merchant.finance'))->assertOk()->assertViewHas('ready', 0);
    }

    public function test_csv_exports_fee_only_after_failure_without_changing_original_goods_value(): void
    {
        $o = app(OrderWorkflow::class)->transition($this->captain, $this->ready(), 'failed', 'تعذر التسليم', 1000);
        $response = $this->asStaff($this->admin)->get(route('admin.orders.export'))->assertOk();
        $lines = explode("\n", $response->streamedContent());
        $row = str_getcsv($lines[2], ';', '"', '');
        $this->assertSame(0.0, (float) $row[4]);
        $this->assertSame(10.0, (float) $row[5]);
        $this->assertSame(10.0, (float) $row[6]);
        $this->assertSame(10000, (int) $o->fresh()->goods_minor);
    }
}
