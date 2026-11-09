<?php

namespace Tests\Feature;

use App\Models\FinancialEntry;
use App\Models\Neighborhood;
use App\Models\Order;
use App\Models\PickupAddress;
use App\Models\StoreAgreement;
use App\Models\Tariff;
use App\Models\Zone;
use App\Services\Delivery\Finance;
use App\Services\Delivery\OrderWorkflow;
use App\Services\Delivery\Payments;
use GazaDelivery\Foundation\Models\Staff;
use GazaDelivery\Foundation\Models\User;
use GazaDelivery\Foundation\Support\PhoneNumber;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CouponDelivery171Test extends TestCase
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
        $this->address = $this->make(PickupAddress::class, ['store_id' => $this->owner->store->id, 'zone_id' => $this->from->id, 'label' => 'المتجر', 'address' => 'العنوان التفصيلي', 'contact_phone' => '0591000009', 'active' => true]);
        $this->tariff = $this->make(Tariff::class, ['from_zone_id' => $this->from->id, 'to_zone_id' => $this->to->id, 'fee_minor' => 1000, 'captain_pay_minor' => 600, 'return_fee_minor' => 300, 'captain_return_pay_minor' => 200, 'active' => true]);
    }

    private function make(string $type, array $data)
    {
        $m = new $type;
        $m->forceFill($data)->save();

        return $m->fresh();
    }

    private function data(array $extra = []): array
    {
        return array_replace(['idempotency_key' => (string) Str::uuid(), 'pickup_address_id' => $this->address->id, 'to_zone_id' => $this->to->id, 'recipient_name' => 'مستلم', 'recipient_phone' => '0593334444', 'recipient_address' => 'عنوان مستلم تجريبي', 'description' => 'ملابس', 'fee_payer' => 'merchant', 'goods_amount' => '100'], $extra);
    }

    private function couponData(): array
    {
        $this->make(\App\Models\Coupon::class, ['code' => 'HALF171', 'store_id' => $this->owner->store->id, 'type' => 'percent', 'value' => 5000, 'max_uses' => 10, 'per_store_limit' => 10, 'starts_on' => today()->subDay(), 'ends_on' => today()->addDay(), 'active' => true]);
        return $this->data(['coupon_code' => 'HALF171']);
    }

    public function test_coupon_collects_goods_only_and_keeps_discounted_merchant_fee(): void
    {
        $data = $this->couponData();
        $this->asMerchant($this->owner)->post(route('merchant.orders.preview'), $data)->assertOk()->assertSee('المتجر (شامل التوصيل)');
        $quote = app(\App\Services\Delivery\Pricing::class)->quote($this->owner->store, $data);
        $this->post(route('merchant.orders.store'), $data + ['expected_fee' => $quote['fee_minor'], 'quote_hash' => \App\Services\Delivery\Pricing::fingerprint($quote)])->assertRedirect();
        $order = Order::firstOrFail();
        $this->assertSame('merchant', $order->fee_payer);
        $this->assertSame(10000, (int) $order->collect_minor);
        $this->assertSame(500, (int) $order->fee_minor);
        $this->assertSame(600, (int) $order->captain_pay_minor);
    }

    public function test_preview_and_final_confirmation_reject_recipient_payer_with_coupon(): void
    {
        $data = $this->couponData();
        $quote = app(\App\Services\Delivery\Pricing::class)->quote($this->owner->store, $data);
        $tampered = array_replace($data, ['fee_payer' => 'recipient']);
        $this->asMerchant($this->owner)->post(route('merchant.orders.preview'), $tampered)->assertSessionHasErrors('fee_payer');
        $this->post(route('merchant.orders.store'), $tampered + ['expected_fee' => $quote['fee_minor'], 'quote_hash' => \App\Services\Delivery\Pricing::fingerprint($quote)])->assertSessionHasErrors('fee_payer');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('coupon_redemptions', 0);
    }

    public function test_without_coupon_recipient_can_still_pay_delivery(): void
    {
        $order = app(OrderWorkflow::class)->create($this->owner, $this->data(['fee_payer' => 'recipient']));
        $this->assertSame('recipient', $order->fee_payer);
        $this->assertSame(11000, (int) $order->collect_minor);
    }

    public function test_prefilled_coupon_shows_included_delivery_hint(): void
    {
        $this->couponData();
        $this->asMerchant($this->owner)->get(route('merchant.orders.create', ['coupon_code' => 'HALF171']))->assertOk()->assertSee('value="HALF171"', false)->assertSee('عند استخدام كوبون يكون الطلب شامل التوصيل فقط.');
    }
}
