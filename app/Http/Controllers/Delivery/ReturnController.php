<?php

namespace App\Http\Controllers\Delivery;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ReturnTask;
use App\Services\Delivery\Returns;
use GazaDelivery\Foundation\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

final class ReturnController extends Controller
{
    private function filtered(Request $r)
    {
        $d = $r->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(Returns::STATUSES)], 'captain_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => array_merge(['nullable', 'date_format:Y-m-d'], $r->filled('from') ? ['after_or_equal:from'] : [])]);
        $q = ReturnTask::whereHas('order', fn ($o) => $o->whereIn('status', Returns::STATUSES))->with('order.store', 'order.captain', 'captain', 'receiver');
        if ($r->is('captain/*')) {
            $q->where('captain_id', $r->user('staff')->id);
        }

        return $q->when($d['status'] ?? null, fn ($q, $s) => $q->whereHas('order', fn ($o) => $o->where('status', $s)))
            ->when($d['captain_id'] ?? null, fn ($q, $id) => $q->where('captain_id', $id))
            ->when($d['q'] ?? null, fn ($q, $s) => $q->whereHas('order', fn ($o) => $o->where(fn ($x) => $x->where('display_reference', 'like', '%'.$s.'%')->orWhereHas('store', fn ($st) => $st->where('name', 'like', '%'.$s.'%')))))
            ->when($d['from'] ?? null, fn ($q, $date) => $q->where('created_at', '>=', Carbon::parse($date, 'Asia/Hebron')->startOfDay()->utc()))
            ->when($d['to'] ?? null, fn ($q, $date) => $q->where('created_at', '<=', Carbon::parse($date, 'Asia/Hebron')->endOfDay()->utc()));
    }

    public function index(Request $r)
    {
        $prefix = $r->is('admin/*') ? 'admin' : 'captain';

        return view('delivery.returns', ['tasks' => $this->filtered($r)->latest('id')->paginate(20)->withQueryString(), 'prefix' => $prefix, 'captains' => $prefix === 'admin' ? Staff::role('captain')->where('status', 'active')->get() : collect()]);
    }

    public function assign(Request $r, string $order, Returns $returns)
    {
        $d = $r->validate(['captain_id' => ['required', 'integer', 'exists:staff,id']]);
        $returns->assign($r->user('staff'), Order::where('public_id', $order)->firstOrFail(), (int) $d['captain_id']);

        return back()->with('status', 'تم إسناد مهمة الإرجاع وإشعار الكابتن.');
    }

    public function export(Request $r)
    {
        $query = $this->filtered($r);

        return response()->streamDownload(function () use ($query) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBFsep=;\r\n");
            fputcsv($f, ['رقم الطلب', 'المتجر', 'الحالة', 'كابتن التوصيل', 'كابتن الإرجاع', 'استلام المكتب', 'إسناد الإرجاع', 'استلام الكابتن', 'تسليم المتجر', 'مستلم المكتب', 'سبب التعذر'], ';', '"', '');
            foreach ($query->latest('id')->cursor() as $t) {
                $row = [$t->order->reference, $t->order->store->name, $t->order->label, $t->order->captain?->name, $t->captain?->name, $t->office_received_at?->timezone('Asia/Hebron')->format('Y-m-d H:i'), $t->assigned_at?->timezone('Asia/Hebron')->format('Y-m-d H:i'), $t->picked_up_at?->timezone('Asia/Hebron')->format('Y-m-d H:i'), $t->completed_at?->timezone('Asia/Hebron')->format('Y-m-d H:i'), $t->receiver?->name, $t->order->events()->where('status', 'failed')->latest('id')->value('note')];
                fputcsv($f, array_map(fn ($v) => preg_match('/^[=+\-@\t\r]/', ltrim((string) $v)) ? "'".$v : $v, $row), ';', '"', '');
            } fclose($f);
        }, 'metjari-returns.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
