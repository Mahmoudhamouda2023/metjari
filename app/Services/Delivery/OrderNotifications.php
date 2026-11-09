<?php

namespace App\Services\Delivery;

use App\Models\DeliveryNotification;
use App\Models\Order;
use App\Models\Remittance;
use GazaDelivery\Foundation\Models\Staff;
use Illuminate\Support\Facades\Schema;

final class OrderNotifications
{
    public function created(Order $order): void
    {
        Staff::where('status', 'active')->role(['super_admin', 'operations', 'finance'])->select('staff.*')->distinct()->get()->each(function ($staff) use ($order) {
            $this->send($staff, $order, 'طلب جديد: '.$order->reference);
        });
    }

    public function assigned(Order $order, Staff $captain): void
    {
        $this->send($captain, $order, 'مهمة مسندة إليك: '.$order->reference);
    }

    public function assignedReturn(Order $order, Staff $captain): void
    {
        $this->send($captain, $order, 'مهمة إرجاع جديدة إلى المتجر: '.$order->reference);
    }

    public function changed(Order $order, string $status): void
    {
        $labels = ['delivered' => 'تأكيد الكابتن تسليم الطلب وتسجيل التحصيل', 'failed' => 'تعذر تسليم الطلب', 'office_pending' => 'الكابتن سلّم المرتجع للمكتب وبانتظار التأكيد', 'office_received' => 'أكد المكتب استلام المرتجع', 'return_picked_up' => 'استلم كابتن الإرجاع الطرد من المكتب', 'return_transit' => 'المرتجع في الطريق للمتجر', 'returned' => 'تم تسجيل إعادة الطلب للمتجر'];
        if (! isset($labels[$status])) {
            return;
        }
        Staff::where('status', 'active')->role(['super_admin', 'operations', 'finance'])->select('staff.*')->distinct()->get()
            ->each(fn ($staff) => $this->send($staff, $order, $labels[$status].': '.$order->reference));
    }

    public function remittanceCreated(Remittance $remittance, $entries): void
    {
        $references = $entries->map(fn ($e) => $e->order?->reference)->filter()->implode('، ');
        $title = 'كشف توريد جديد #'.$remittance->id.' من '.$remittance->captain->name.' · '.$entries->count().' طلبات · '.$references;

        // v1.4.1 added target_url and made order_id nullable. On databases that
        // have not applied that migration yet, use the first order as a safe
        // fallback so the captain's remittance is not blocked by notifications.
        $hasDirectLink = Schema::hasColumn('delivery_notifications', 'target_url');
        $firstOrderId = $entries->first()?->order_id;

        Staff::where('status', 'active')->role(['super_admin', 'finance'])
            ->select('staff.*')->distinct()->get()
            ->each(function ($staff) use ($title, $remittance, $hasDirectLink, $firstOrderId) {
                $attributes = [
                    'staff_id' => $staff->id,
                    'order_id' => $hasDirectLink ? null : $firstOrderId,
                    'title' => mb_substr($title, 0, 240),
                ];
                if ($hasDirectLink) {
                    $attributes['target_url'] = route('admin.finance.receipt', [
                        'kind' => 'remittance', 'id' => $remittance->id,
                    ]);
                }
                $n = new DeliveryNotification;
                $n->forceFill($attributes)->save();
            });
    }

    private function send(Staff $staff, Order $order, string $title): void
    {
        $n = new DeliveryNotification;
        $n->forceFill(['staff_id' => $staff->id, 'order_id' => $order->id, 'title' => $title])->save();
    }
}
