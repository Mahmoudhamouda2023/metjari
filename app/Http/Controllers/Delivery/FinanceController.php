<?php

namespace App\Http\Controllers\Delivery;

use App\Http\Controllers\Controller;
use App\Models\CaptainPayout;
use App\Models\Expense;
use App\Models\FinancialEntry;
use App\Models\Remittance;
use App\Models\Settlement;
use App\Models\Subscription;
use App\Services\Delivery\Audit;
use App\Services\Delivery\Finance;
use App\Services\Delivery\FinancialBatches;
use App\Services\Delivery\Money;
use GazaDelivery\Foundation\Models\Staff;
use GazaDelivery\Foundation\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FinanceController extends Controller
{
    public function index(Request $r)
    {
        $section = match ($r->route()->getName()) {
            'admin.finance.stores' => 'stores', 'admin.finance.captains' => 'captains',
            'admin.finance.remittances' => 'remittances', default => 'overview',
        };
        if ($r->routeIs('admin.finance.live')) {
            $section = $r->validate(['section' => ['nullable', Rule::in(['overview','stores','captains','remittances'])]])['section'] ?? 'overview';
        }
        $historyKind = ['stores'=>'settlement','captains'=>'captain','remittances'=>'remittance'][$section] ?? null;
        $ready = FinancialEntry::whereIn('entry_kind', ['delivery', 'failed_delivery', 'return_delivery'])->whereNull('settlement_id')->cleared()->selectRaw('store_id, SUM(merchant_net_minor) as amount, COUNT(*) as entries')->groupBy('store_id')->get();
        $storeNames = Store::whereIn('id', $ready->pluck('store_id'))->pluck('name', 'id');
        $pay = FinancialEntry::whereNull('captain_payout_id')->cleared()->selectRaw('captain_id, SUM(captain_pay_minor) as amount, COUNT(*) as entries')->groupBy('captain_id')->get();

        $data = [
            'financeSection' => $section,
            'settlementHashes' => FinancialEntry::whereIn('entry_kind', ['delivery', 'failed_delivery', 'return_delivery'])->whereNull('settlement_id')->cleared()->orderBy('id')->get(['id', 'store_id'])->groupBy('store_id')->map(fn ($rows) => Finance::fingerprint($rows->modelKeys())),
            'payoutHashes' => FinancialEntry::whereNull('captain_payout_id')->cleared()->orderBy('id')->get(['id', 'captain_id'])->groupBy('captain_id')->map(fn ($rows) => Finance::fingerprint($rows->modelKeys())),
            'ready' => $ready, 'storeNames' => $storeNames, 'pay' => $pay, 'captainNames' => Staff::whereIn('id', $pay->pluck('captain_id'))->pluck('name', 'id'),
            'remittances' => Remittance::with('captain')->latest('id')->paginate(15),
            'fees' => FinancialEntry::sum('fee_minor'), 'commissions' => FinancialEntry::sum('captain_pay_minor'), 'expenses' => Expense::sum('amount_minor'),
            'cash' => Remittance::where('status', 'approved')->sum('amount_minor'), 'packageRevenue' => Subscription::where('status', 'active')->sum('price_minor'),
            'settlements' => Settlement::with('store')->latest('id')->limit(20)->get(), 'payouts' => CaptainPayout::with('captain')->latest('id')->limit(20)->get(),
            'expenseRows' => Expense::latest('id')->limit(20)->get(),
            'history' => $this->historyQuery()->when($historyKind, fn ($q, $kind) => $q->where('kind', $kind))->orderByDesc('created_at')->orderByDesc('id')->orderBy('kind')->limit(5)->get(),
            'uncollectedFees' => FinancialEntry::where('fee_collection_required', true)->whereNull('fee_collected_at')->sum('fee_minor'),
        ];
        if ($r->routeIs('admin.finance.live')) {
            return response()->json(['html' => view('delivery.finance-content', $data)->render()])->header('Cache-Control', 'no-store, private');
        }

        return view('delivery.finance', $data);
    }

    private function historyQuery()
    {
        $settlements = DB::table('settlements as b')->join('stores as n', 'n.id', '=', 'b.store_id')
            ->selectRaw("'settlement' as kind, b.id, n.name as beneficiary, b.amount_minor, b.reference, b.created_at, CASE WHEN b.voided_at IS NULL THEN 'approved' ELSE 'voided' END as status");
        $payouts = DB::table('captain_payouts as b')->join('staff as n', 'n.id', '=', 'b.captain_id')
            ->selectRaw("'captain' as kind, b.id, n.name as beneficiary, b.amount_minor, b.reference, b.created_at, CASE WHEN b.voided_at IS NULL THEN 'approved' ELSE 'voided' END as status");
        $remittances = DB::table('remittances as b')->join('staff as n', 'n.id', '=', 'b.captain_id')
            ->selectRaw("'remittance' as kind, b.id, n.name as beneficiary, b.amount_minor, b.reference, b.created_at, b.status");

        return DB::query()->fromSub($settlements->unionAll($payouts)->unionAll($remittances), 'financial_history');
    }

    public function history(Request $r)
    {
        $d = $r->validate(['kind' => ['nullable', Rule::in(['settlement', 'captain', 'remittance'])], 'q' => ['nullable', 'string', 'max:160']]);
        $q = $this->historyQuery()->when($d['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($d['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.$term.'%')->orWhere('beneficiary', 'like', '%'.$term.'%')));

        return view('delivery.finance-history', ['history' => $q->orderByDesc('created_at')->orderByDesc('id')->orderBy('kind')->paginate(20)->withQueryString()]);
    }

    public function remittance(Request $r, Remittance $remittance, Finance $finance)
    {
        $d = $r->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'review_note' => ['nullable', 'required_if:decision,rejected', 'string', 'min:3', 'max:500'],
            'received_amount' => ['nullable', 'regex:/^\d{1,11}(\.\d{1,2})?$/'],
        ]);
        $finance->reviewRemittance($r->user('staff'), $remittance, $d['decision'] === 'approved', $d['review_note'] ?? '', isset($d['received_amount']) ? Money::minor($d['received_amount'], 'received_amount') : null);

        return back()->with('status', 'تم تسجيل قرار التوريد.');
    }

    public function settle(Request $r, Store $store, Finance $finance)
    {
        $d = $r->validate(['entry_hash' => ['required', 'string', 'size:64'], 'expected_minor' => ['required', 'integer', 'between:-99999999999,99999999999']]);
        $s = $finance->settle($r->user('staff'), $store, '', (int) $d['expected_minor'], $d['entry_hash']);

        if ($r->expectsJson()) {
            return response()->json(['message' => 'تم تسجيل التسوية وتحديث الرصيد.', 'receipt_url' => route('admin.finance.receipt', ['kind' => 'settlement', 'id' => $s->id])]);
        }

        return redirect()->route('admin.finance.receipt', ['kind' => 'settlement', 'id' => $s->id])->with('status', 'تم تسجيل دفع التسوية.');
    }

    public function payout(Request $r, Staff $captain, Finance $finance)
    {
        $d = $r->validate(['entry_hash' => ['required', 'string', 'size:64'], 'expected_minor' => ['required', 'integer', 'between:0,99999999999']]);
        $p = $finance->payCaptain($r->user('staff'), $captain, '', (int) $d['expected_minor'], $d['entry_hash']);

        if ($r->expectsJson()) {
            return response()->json(['message' => 'تم تسجيل صرف أجرة الكابتن وتحديث الرصيد.', 'receipt_url' => route('admin.finance.receipt', ['kind' => 'captain', 'id' => $p->id])]);
        }

        return redirect()->route('admin.finance.receipt', ['kind' => 'captain', 'id' => $p->id])->with('status', 'تم تسجيل صرف المستحقات.');
    }

    public function expense(Request $r)
    {
        $d = $r->validate(['spent_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'category' => ['required', 'string', 'max:100'], 'description' => ['required', 'string', 'max:500'], 'amount' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/'], 'reference' => ['required', 'string', 'min:3', 'max:160'], 'submission_key' => ['required', 'uuid']]);
        $amount = Money::minor($d['amount']);
        if ($amount < 1) {
            throw ValidationException::withMessages(['amount' => 'المبلغ يجب أن يكون أكبر من صفر.']);
        } unset($d['amount']);
        DB::transaction(function () use ($r, $d, $amount) {
            Staff::whereKey($r->user('staff')->id)->lockForUpdate()->firstOrFail();
            if ($existing = Expense::where('submission_key', $d['submission_key'])->first()) {
                if ($existing->recorded_by !== $r->user('staff')->id || (int) $existing->amount_minor !== $amount || $existing->reference !== $d['reference'] || $existing->description !== $d['description'] || $existing->category !== $d['category'] || $existing->spent_on->toDateString() !== $d['spent_on']) {
                    throw ValidationException::withMessages(['submission_key' => 'تم استخدام هذا النموذج لعملية مختلفة. حدّث الصفحة لإدخال مصروف جديد.']);
                }

                return;
            }
            $e = new Expense;
            $e->forceFill($d + ['amount_minor' => $amount, 'recorded_by' => $r->user('staff')->id])->save();
            Audit::log($r->user('staff'), 'expense.recorded', (string) $e->id, ['amount_minor' => $amount]);
        });

        return back()->with('status', 'تم تسجيل المصروف.');
    }

    public function captain(Request $r)
    {
        $id = $r->user('staff')->id;

        return view('delivery.captain-finance', ['unremitted' => FinancialEntry::where('captain_id', $id)->paymentVerified()->whereNull('remittance_id')->sum('collected_minor'), 'remittances' => Remittance::where('captain_id', $id)->latest('id')->paginate(15), 'earned' => FinancialEntry::where('captain_id', $id)->sum('captain_pay_minor'), 'paid' => CaptainPayout::where('captain_id', $id)->whereNull('voided_at')->sum('amount_minor'), 'payoutHistory' => CaptainPayout::where('captain_id', $id)->latest('id')->limit(20)->get(), 'entries' => FinancialEntry::where('captain_id', $id)->with('order')->latest('id')->limit(30)->get()]);
    }

    public function requestRemittance(Request $r, Finance $finance)
    {
        $batch = $finance->requestRemittance($r->user('staff'));
        $items = FinancialBatches::entries('remittance', $batch->id);
        $refs = $items->map(fn ($entry) => $entry->order?->reference)->filter()->implode('، ');

        $message = 'تم إنشاء كشف توريد #'.$batch->id.' للطلبات: '.$refs.'. بانتظار استلام الإدارة للمبلغ واعتماده.';
        if ($r->expectsJson()) {
            return response()->json(['message'=>$message, 'batch_id'=>$batch->id, 'reference'=>$batch->internal_reference]);
        }

        return redirect()->route('captain.finance', [], 303)->with('status', $message);
    }

    public function merchant(Request $r)
    {
        $id = $r->user('merchant')->store->id;

        return view('delivery.merchant-finance', ['entries' => FinancialEntry::whereIn('entry_kind', ['delivery', 'failed_delivery', 'return_delivery'])->where('store_id', $id)->with('order.payment', 'remittance')->latest('id')->paginate(20), 'settlements' => Settlement::where('store_id', $id)->whereNull('voided_at')->latest('id')->limit(30)->get(), 'ready' => FinancialEntry::whereIn('entry_kind', ['delivery', 'failed_delivery', 'return_delivery'])->where('store_id', $id)->whereNull('settlement_id')->cleared()->sum('merchant_net_minor'), 'pending' => FinancialEntry::whereIn('entry_kind', ['delivery', 'failed_delivery', 'return_delivery'])->where('store_id', $id)->whereNull('settlement_id')->sum('merchant_net_minor')]);
    }

    public function reverse(Request $r, string $kind, int $id, FinancialBatches $batches)
    {
        $d = $r->validate(['reason' => ['required', 'string', 'min:5', 'max:500'], 'funds_returned' => ['required', 'accepted']]);
        $batches->reverse($r->user('staff'), $kind, $id, $d['reason']);

        return back()->with('status', 'تم تسجيل العكس مع الاحتفاظ بالكشف الأصلي. عادت الطلبات للحساب المفتوح.');
    }

    public function received(Request $r)
    {
        $d = $r->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => array_merge(['nullable', 'date_format:Y-m-d'], $r->filled('from') ? ['after_or_equal:from'] : [])]);
        $id = $r->user('merchant')->store->id;
        $start = ! empty($d['from']) ? Carbon::parse($d['from'], 'Asia/Hebron')->startOfDay()->utc() : null;
        $end = ! empty($d['to']) ? Carbon::parse($d['to'], 'Asia/Hebron')->endOfDay()->utc() : null;
        $settlements = Settlement::where('store_id', $id)->when($start, fn ($q) => $q->where('created_at', '>=', $start))->when($end, fn ($q) => $q->where('created_at', '<=', $end));
        $direct = FinancialEntry::whereIn('entry_kind', ['delivery', 'failed_delivery', 'return_delivery'])->where('store_id', $id)->where('merchant_received_minor', '>', 0)->paymentVerified()
            ->whereHas('order.payment', fn ($p) => $p->where('status', 'approved')->when($start, fn ($q) => $q->where('reviewed_at', '>=', $start))->when($end, fn ($q) => $q->where('reviewed_at', '<=', $end)));
        $officeReceived = (int) (clone $settlements)->whereNull('voided_at')->where('amount_minor', '>', 0)->sum('amount_minor');
        $paidToOffice = -(int) (clone $settlements)->whereNull('voided_at')->where('amount_minor', '<', 0)->sum('amount_minor');
        $directReceived = (int) (clone $direct)->sum('merchant_received_minor');
        $batches = (clone $settlements)->latest('id')->paginate(15, ['*'], 'settlement_page')->withQueryString();
        $transfers = (clone $direct)->with('order.payment')->latest('id')->paginate(15, ['*'], 'transfer_page')->withQueryString();

        return view('delivery.merchant-received', compact('officeReceived', 'paidToOffice', 'directReceived', 'batches', 'transfers'));
    }

    public function receipt(Request $r, string $kind, int $id)
    {
        $isMerchant = $r->is('merchant/*');
        if ($kind === 'settlement') {
            $q = Settlement::query();
            if ($isMerchant) {
                $q->where('store_id', $r->user('merchant')->store->id);
            } $batch = $q->findOrFail($id);
            $name = $batch->store->name;
            $title = 'كشف تسوية المتجر';
        } elseif ($kind === 'captain' && ! $isMerchant) {
            $batch = CaptainPayout::findOrFail($id);
            $name = $batch->captain->name;
            $title = 'كشف صرف مستحقات الكابتن';
        } elseif ($kind === 'remittance' && ! $isMerchant) {
            $batch = Remittance::findOrFail($id);
            $name = $batch->captain->name;
            $title = 'كشف توريد التحصيل';
        } else {
            abort(404);
        }

        return view('delivery.receipt', ['batch' => $batch, 'name' => $name, 'title' => $title, 'kind' => $kind, 'entries' => FinancialBatches::entries($kind, $batch->id)]);
    }
}
