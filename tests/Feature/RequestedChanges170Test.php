<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Models\CaptainPayout;
use App\Models\FinancialEntry;
use App\Models\Order;
use App\Models\PickupAddress;
use App\Models\Remittance;
use App\Models\Settlement;
use App\Models\Tariff;
use App\Models\Zone;
use App\Services\Delivery\OrderWorkflow;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RequestedChanges170Test extends TestCase
{
    private $owner;

    private $admin;

    private $captain;

    private $address;

    private $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->merchant(true);
        $this->admin = $this->staff();
        $this->captain = $this->staff('captain', '0591000002');
        $from = $this->make(Zone::class, ['name' => 'غزة', 'active' => true]);
        $this->to = $this->make(Zone::class, ['name' => 'الشمال', 'active' => true]);
        $this->address = $this->make(PickupAddress::class, ['store_id' => $this->owner->store->id, 'zone_id' => $from->id, 'label' => 'المتجر', 'address' => 'عنوان المتجر التجريبي', 'contact_phone' => '0591000009', 'active' => true]);
        $this->make(Tariff::class, ['from_zone_id' => $from->id, 'to_zone_id' => $this->to->id, 'fee_minor' => 1000, 'captain_pay_minor' => 600, 'return_fee_minor' => 300, 'captain_return_pay_minor' => 200, 'active' => true]);
    }

    private function make(string $class, array $data)
    {
        $record = new $class;
        $record->forceFill($data)->save();

        return $record->fresh();
    }

    private function ready(): Order
    {
        $w = app(OrderWorkflow::class);
        $o = $w->create($this->owner, ['idempotency_key' => (string) Str::uuid(), 'pickup_address_id' => $this->address->id, 'to_zone_id' => $this->to->id, 'recipient_name' => 'مستلم', 'recipient_phone' => '0593334444', 'recipient_address' => 'عنوان المستلم التجريبي', 'description' => 'طرد', 'fee_payer' => 'merchant', 'goods_amount' => '100']);
        $o = $w->assign($this->admin, $o, $this->captain->id);
        $o = $w->transition($this->captain, $o, 'picked_up');

        return $w->transition($this->captain, $o, 'out_for_delivery');
    }

    public function test_breeze_is_installed_and_the_authentication_routes_use_its_adapted_scaffold(): void
    {
        $this->assertSame('v2.4.2', InstalledVersions::getPrettyVersion('laravel/breeze'));
        foreach (['merchant.login.store', 'staff.login.store'] as $name) {
            $this->assertSame(AuthenticatedSessionController::class, Route::getRoutes()->getByName($name)->getControllerClass());
        }
        $this->assertSame(RegisteredUserController::class, Route::getRoutes()->getByName('register.store')->getControllerClass());
        $this->get(route('login'))->assertOk();
        $this->get(route('staff.login'))->assertOk();
    }

    public function test_admin_financial_pages_have_separate_regions_and_histories(): void
    {
        $this->make(Settlement::class, ['store_id' => $this->owner->store->id, 'amount_minor' => 500, 'reference' => 'STL-TEST', 'paid_by' => $this->admin->id]);
        $this->make(CaptainPayout::class, ['captain_id' => $this->captain->id, 'amount_minor' => 200, 'reference' => 'PAY-TEST', 'paid_by' => $this->admin->id]);
        $this->make(Remittance::class, ['captain_id' => $this->captain->id, 'amount_minor' => 1000, 'reference' => 'REM-TEST', 'status' => 'pending']);
        $this->asStaff($this->admin);
        foreach (['stores' => ['ready', 'settlement'], 'captains' => ['pay', 'captain'], 'remittances' => ['remittances', 'remittance']] as $section => [$region,$kind]) {
            $response = $this->get(route('admin.finance.'.$section))->assertOk()->assertViewHas('financeSection', $section)
                ->assertSee('data-finance-region="'.$region.'"', false)->assertViewHas('history', fn ($rows) => $rows->count() === 1 && $rows->first()->kind === $kind);
            foreach (['ready', 'pay', 'remittances'] as $other) {
                if ($other !== $region) {
                    $response->assertDontSee('data-finance-region="'.$other.'"', false);
                }
            }
            $html = $this->getJson(route('admin.finance.live', ['section' => $section]))->assertOk()->json('html');
            $this->assertStringContainsString('data-finance-region="'.$region.'"', $html);
        }
    }

    public function test_captain_cannot_open_admin_financial_pages_or_their_live_regions(): void
    {
        $this->asStaff($this->captain);
        foreach (['stores', 'captains', 'remittances'] as $section) {
            $this->get(route('admin.finance.'.$section))->assertForbidden();
            $this->getJson(route('admin.finance.live', ['section' => $section]))->assertForbidden();
        }
    }

    public function test_captain_remittance_uses_json_and_repeating_does_not_repeat_the_same_funds(): void
    {
        $o = app(OrderWorkflow::class)->transition($this->captain, $this->ready(), 'delivered', null, 10000);
        $response = $this->asStaff($this->captain)->postJson(route('captain.remittances.store'))->assertOk()->assertJsonStructure(['message', 'batch_id', 'reference']);
        $this->assertFalse($response->headers->has('Location'));
        $this->assertDatabaseCount('remittances', 1);
        $this->assertSame(10000, (int) Remittance::firstOrFail()->amount_minor);
        $this->get(route('captain.finance'))->assertOk()->assertSee('data-ajax-root="captain-finance"', false);
        $this->postJson(route('captain.remittances.store'))->assertUnprocessable();
        $this->assertDatabaseCount('remittances', 1);
    }

    public function test_failed_delivery_ajax_response_and_view_show_fee_only_without_rewriting_original_goods(): void
    {
        $o = $this->ready();
        $this->asStaff($this->captain)->patchJson(route('captain.orders.status', $o), ['status' => 'failed', 'note' => 'رفض استلام البضاعة', 'collected_amount' => '10'])
            ->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('goods_due_minor', 0)->assertJsonPath('collection_due_minor', 1000);
        $this->get(route('captain.orders.show', $o))->assertOk()->assertSee('data-ajax-root="order"', false)->assertSee('data-goods-due', false)->assertSee('0.00 ₪')->assertSee('أجرة محاولة التسليم');
        $this->assertSame(10000, (int) $o->fresh()->goods_minor);
        $this->assertDatabaseCount('financial_entries', 1);
        $this->assertSame(1000, (int) FinancialEntry::firstOrFail()->collected_minor);
    }

    public function test_invalid_ajax_fee_keeps_the_order_and_ledger_unchanged(): void
    {
        $o = $this->ready();
        $this->asStaff($this->captain)->patchJson(route('captain.orders.status', $o), ['status' => 'failed', 'note' => 'تعذر التسليم', 'collected_amount' => '100'])->assertUnprocessable();
        $this->assertSame('out_for_delivery',$o->fresh()->status);
        $this->assertDatabaseCount('financial_entries',0);
    }
}
