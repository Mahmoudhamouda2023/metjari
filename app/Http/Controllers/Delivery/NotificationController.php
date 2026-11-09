<?php

namespace App\Http\Controllers\Delivery;

use App\Http\Controllers\Controller;
use App\Models\DeliveryNotification;
use App\Models\Order;
use App\Models\OrderEvent;
use Illuminate\Http\Request;

final class NotificationController extends Controller
{
    private function merchant(Request $r): bool
    {
        return $r->is('merchant/*');
    }

    private function scopeOrders($q, Request $r)
    {
        if ($this->merchant($r)) {
            return $q->where('store_id', $r->user('merchant')->store->id);
        }
        if ($r->is('captain/*')) {
            return $q->where(fn ($x) => $x->where('captain_id', $r->user('staff')->id)->orWhereHas('returnTask', fn ($t) => $t->where('captain_id', $r->user('staff')->id)));
        }

        return $q;
    }

    private function query(Request $r)
    {
        if ($this->merchant($r)) {
            return DeliveryNotification::where('user_id', $r->user('merchant')->id)->whereNull('staff_id');
        }
        $staff = $r->user('staff');
        $admin = $r->is('admin/*');
        abort_unless($staff->isActive() && ($admin ? $staff->hasAnyRole(['super_admin', 'operations', 'finance']) : $staff->hasRole('captain')), 403);

        return DeliveryNotification::where('staff_id', $staff->id)->whereNull('user_id')->where(function ($q) use ($r, $admin) {
            $q->whereHas('order', fn ($o) => $this->scopeOrders($o, $r));
            if ($admin) {
                $q->orWhereNull('order_id');
            }
        });
    }

    public function index(Request $r)
    {
        $q = $this->query($r);
        $prefix = $this->merchant($r) ? 'merchant' : ($r->is('admin/*') ? 'admin' : 'captain');
        $events = OrderEvent::whereHas('order', fn ($o) => $this->scopeOrders($o, $r))->max('id');

        $financeVersion = '';
        if ($this->merchant($r)) {
            $storeId = $r->user('merchant')->store->id;
            $settlements = \App\Models\Settlement::where('store_id', $storeId);
            $financeVersion = ':'.(int) (clone $settlements)->max('id').':'.(clone $settlements)->whereNotNull('voided_at')->count()
                .':'.\App\Models\OrderPayment::whereHas('order', fn ($q) => $q->where('store_id', $storeId))->where('status', 'approved')->count();
        }

        return response()->json(['latest' => (int) (clone $q)->max('id'), 'version' => (string) $events.':'.$this->scopeOrders(Order::query(), $r)->count().':'.(int) (clone $q)->max('id').$financeVersion, 'unread' => (clone $q)->whereNull('read_at')->count(),
            'items' => (clone $q)->whereNull('read_at')->latest('id')->limit(20)->with('order')->get()->map(fn ($n) => ['id' => $n->id, 'title' => $n->title, 'url' => $n->target_url ?: ($n->order ? route($prefix.'.orders.show', $n->order) : route($prefix === 'merchant' ? 'merchant.points' : 'admin.finance'))])])->header('Cache-Control', 'no-store, private');
    }

    public function read(Request $r)
    {
        $d = $r->validate([
            'notification_id' => ['required_without:through', 'prohibits:through', 'integer', 'min:1'],
            'through' => ['required_without:notification_id', 'prohibits:notification_id', 'integer', 'min:0'],
        ]);
        $owned = $this->query($r);
        if (isset($d['notification_id'])) {
            // The identity scope also checks current access to the assigned order.
            // Reading one notice must never mark its older siblings as read.
            $notice = (clone $owned)->whereKey($d['notification_id'])->firstOrFail();
            (clone $owned)->whereKey($notice->id)->whereNull('read_at')->update(['read_at' => now()]);
        } else {
            (clone $owned)->where('id', '<=', $d['through'])->whereNull('read_at')->update(['read_at' => now()]);
        }

        return response()->json(['ok' => true, 'unread' => (clone $owned)->whereNull('read_at')->count()])
            ->header('Cache-Control', 'no-store, private');
    }
}
