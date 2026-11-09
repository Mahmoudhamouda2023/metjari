<?php

namespace App\Services\Delivery;

use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\ReturnTask;
use GazaDelivery\Foundation\Models\Staff;
use GazaDelivery\Foundation\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class Returns
{
    public const STATUSES = ['office_pending', 'office_received', 'return_assigned', 'return_picked_up', 'return_transit', 'returned'];

    public function assign(Staff $actor, Order $order, int $captainId): void
    {
        abort_unless($actor->isActive() && $actor->hasAnyRole(['super_admin', 'operations']), 403);
        DB::transaction(function () use ($actor, $order, $captainId) {
            Store::whereKey($order->store_id)->lockForUpdate()->firstOrFail();
            $o = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($o->status, ['office_received', 'return_assigned'], true)) {
                throw ValidationException::withMessages(['captain_id' => 'الإسناد متاح بعد تأكيد المكتب وقبل استلام الكابتن فقط.']);
            }
            $captain = Staff::whereKey($captainId)->lockForUpdate()->firstOrFail();
            if (! $captain->isActive() || ! $captain->hasRole('captain') || ! $captain->captainProfile) {
                throw ValidationException::withMessages(['captain_id' => 'اختر كابتنًا مفعّلًا لديه بيانات توصيل.']);
            }
            $task = ReturnTask::where('order_id', $o->id)->lockForUpdate()->firstOrFail();
            if ($o->status === 'return_assigned' && (int) $task->captain_id === $captainId) {
                return;
            }
            $task->forceFill(['captain_id' => $captainId, 'assigned_by' => $actor->id, 'assigned_at' => now()])->save();
            $o->status = 'return_assigned';
            $o->save();
            $event = new OrderEvent;
            $event->forceFill(['order_id' => $o->id, 'status' => $o->status, 'staff_id' => $actor->id, 'note' => 'إسناد مهمة إرجاع إلى '.$captain->name])->save();
            app(OrderNotifications::class)->assignedReturn($o, $captain);
        }, 3);
    }

    // Called inside the locked order transition transaction.
    public function record(Order $order, Staff $actor, string $target): void
    {
        if ($target === 'office_pending') {
            $task = new ReturnTask;
            $task->forceFill(['order_id' => $order->id, 'office_handed_at' => now()])->save();
            $order->setRelation('returnTask', $task);

            return;
        }
        if (! in_array($target, self::STATUSES, true)) {
            return;
        }
        $task = ReturnTask::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
        $fields = match ($target) {
            'office_received' => ['office_received_at' => now(), 'received_by' => $actor->id],
            'return_picked_up' => ['picked_up_at' => now()],
            'return_transit' => ['departed_at' => now()],
            'returned' => ['completed_at' => now()],
            default => [],
        };
        $task->forceFill($fields)->save();
        $order->setRelation('returnTask', $task);
    }
}
