<?php

namespace Tests\Feature;

use App\Models\DeliveryNotification;
use App\Models\Order;
use App\Models\PickupAddress;
use App\Models\Tariff;
use App\Models\Zone;
use App\Services\Delivery\OrderWorkflow;
use GazaDelivery\Foundation\Models\Staff;
use GazaDelivery\Foundation\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NotificationReadTest extends TestCase
{
    private Staff $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->staff();
        $this->owner = $this->merchant(true);
    }

    private function notice(array $attributes = []): DeliveryNotification
    {
        $notice = new DeliveryNotification;
        $notice->forceFill(array_replace(['staff_id' => $this->admin->id, 'user_id' => null, 'order_id' => null, 'title' => 'إشعار للاختبار', 'target_url' => route('admin.finance')], $attributes))->save();

        return $notice;
    }

    private function order(Staff $captain): Order
    {
        $from = new Zone;
        $from->forceFill(['name' => 'غزة', 'active' => true])->save();
        $to = new Zone;
        $to->forceFill(['name' => 'الشمال', 'active' => true])->save();
        $address = new PickupAddress;
        $address->forceFill(['store_id' => $this->owner->store->id, 'zone_id' => $from->id, 'label' => 'المتجر', 'address' => 'عنوان متجر الاختبار', 'contact_phone' => '0591000009', 'active' => true])->save();
        $tariff = new Tariff;
        $tariff->forceFill(['from_zone_id' => $from->id, 'to_zone_id' => $to->id, 'fee_minor' => 1000, 'captain_pay_minor' => 600, 'return_fee_minor' => 300, 'captain_return_pay_minor' => 200, 'active' => true])->save();
        $w = app(OrderWorkflow::class);
        $order = $w->create($this->owner, ['idempotency_key' => (string) Str::uuid(), 'pickup_address_id' => $address->id, 'to_zone_id' => $to->id, 'recipient_name' => 'مستلم', 'recipient_phone' => '0593334444', 'recipient_address' => 'عنوان المستلم للاختبار', 'description' => 'طرد', 'fee_payer' => 'merchant', 'goods_amount' => '100.00']);

        return $w->assign($this->admin, $order, $captain->id);
    }

    public function test_opening_one_notification_only_reads_that_one_and_returns_remaining_count(): void
    {
        $first = $this->notice();
        $opened = $this->notice();
        $last = $this->notice();
        $this->asStaff($this->admin)->postJson(route('admin.notifications.read'), ['notification_id' => $opened->id])->assertOk()->assertJsonPath('unread', 2);
        $this->assertNotNull($opened->fresh()->read_at);
        $this->assertNull($first->fresh()->read_at);
        $this->assertNull($last->fresh()->read_at);
        $this->getJson(route('admin.notifications.index'))->assertJsonPath('unread', 2)->assertJsonCount(2, 'items');
    }

    public function test_repeated_read_is_idempotent_and_preserves_first_read_timestamp(): void
    {
        $notice = $this->notice();
        $this->asStaff($this->admin)->postJson(route('admin.notifications.read'), ['notification_id' => $notice->id])->assertOk()->assertJsonPath('unread', 0);
        $firstRead = $notice->fresh()->read_at->toIso8601String();
        $this->travel(5)->minutes();
        $this->postJson(route('admin.notifications.read'), ['notification_id' => $notice->id])->assertOk()->assertJsonPath('unread', 0);
        $this->assertSame($firstRead, $notice->fresh()->read_at->toIso8601String());
        $this->assertDatabaseCount('delivery_notifications', 1);
    }

    public function test_staff_cannot_read_another_staff_members_notification(): void
    {
        $other = $this->staff('finance', '0591000003');
        $foreign = $this->notice(['staff_id' => $other->id]);
        $this->asStaff($this->admin)->postJson(route('admin.notifications.read'), ['notification_id' => $foreign->id])->assertNotFound();
        $this->postJson(route('admin.notifications.read'), ['notification_id' => 999999])->assertNotFound();
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_merchants_read_only_their_own_notification_even_when_staff_and_user_ids_match(): void
    {
        // MySQL does not roll auto-increment counters back between test cases.
        // Build the collision explicitly instead of assuming matching sequences.
        if ($this->admin->id !== $this->owner->id) {
            $collision = new Staff;
            $collision->forceFill(['id' => $this->owner->id, 'name' => 'موظف برقم مطابق', 'phone' => '0591000004', 'password' => 'SecureTemporary123!', 'status' => 'active', 'must_change_password' => false])->save();
            $collision->assignRole('super_admin');
            $this->admin = $collision->fresh();
        }
        $this->assertSame($this->admin->id, $this->owner->id);
        $staffNotice = $this->notice();
        $own = $this->notice(['staff_id' => null, 'user_id' => $this->owner->id, 'target_url' => route('merchant.points')]);
        $another = $this->merchant(true, '0592000002');
        $foreign = $this->notice(['staff_id' => null, 'user_id' => $another->id]);
        $this->asMerchant($this->owner)->getJson(route('merchant.notifications.index'))->assertJsonPath('unread', 1);
        $this->postJson(route('merchant.notifications.read'), ['notification_id' => $staffNotice->id])->assertNotFound();
        $this->postJson(route('merchant.notifications.read'), ['notification_id' => $foreign->id])->assertNotFound();
        $this->postJson(route('merchant.notifications.read'), ['notification_id' => $own->id])->assertOk()->assertJsonPath('unread', 0);
        $this->assertNull($staffNotice->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
        $this->assertNotNull($own->fresh()->read_at);
        $this->asStaff($this->admin)->postJson(route('admin.notifications.read'), ['notification_id' => $own->id])->assertNotFound();
    }

    public function test_captain_reads_one_assigned_notice_and_cannot_read_it_after_reassignment(): void
    {
        $captain = $this->staff('captain', '0591000002');
        $order = $this->order($captain);
        $assignment = DeliveryNotification::where('staff_id', $captain->id)->firstOrFail();
        $unopened = $this->notice(['staff_id' => $captain->id, 'order_id' => $order->id, 'target_url' => null]);
        $this->asStaff($captain)->postJson(route('captain.notifications.read'), ['notification_id' => $assignment->id])->assertOk()->assertJsonPath('unread', 1);
        $this->assertNull($unopened->fresh()->read_at);
        $other = $this->staff('captain', '0591000003');
        app(OrderWorkflow::class)->assign($this->admin, $order, $other->id);
        $this->postJson(route('captain.notifications.read'), ['notification_id' => $unopened->id])->assertNotFound();
        $this->assertNull($unopened->fresh()->read_at);
    }

    public function test_ambiguous_missing_and_invalid_payloads_do_not_mark_anything_read(): void
    {
        $notice = $this->notice();
        $this->asStaff($this->admin);
        foreach ([[], ['notification_id' => 0], ['notification_id' => 'invalid'], ['notification_id' => $notice->id, 'through' => $notice->id]] as $payload) {
            $this->postJson(route('admin.notifications.read'), $payload)->assertUnprocessable();
        }
        $this->assertNull($notice->fresh()->read_at);
    }

    public function test_bulk_read_cursor_still_preserves_newer_and_foreign_notifications(): void
    {
        $first = $this->notice();
        $second = $this->notice();
        $newer = $this->notice();
        $foreign = $this->notice(['staff_id' => null, 'user_id' => $this->owner->id]);
        $this->asStaff($this->admin)->postJson(route('admin.notifications.read'), ['through' => $second->id])->assertOk()->assertJsonPath('unread', 1);
        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNotNull($second->fresh()->read_at);
        $this->assertNull($newer->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);
    }
}
