<?php

namespace App\Http\Controllers\Delivery;

use App\Http\Controllers\Controller;
use App\Models\CouponRedemption;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyEntry;
use App\Models\LoyaltySetting;
use App\Models\RewardRequest;
use App\Services\Delivery\Audit;
use App\Services\Delivery\Loyalty;
use GazaDelivery\Foundation\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class LoyaltyController extends Controller
{
    public function merchant(Request $r, Loyalty $loyalty)
    {
        $id = $r->user('merchant')->store->id;
        $account = LoyaltyAccount::where('store_id', $id)->first();
        $gifts = RewardRequest::where('store_id', $id)->where('status', 'approved')->with('coupon')->latest('id')->get();
        $used = CouponRedemption::whereIn('coupon_id', $gifts->pluck('coupon_id'))->with('order')->get()->keyBy('coupon_id');

        return view('delivery.points', ['settings' => $loyalty->settings(), 'account' => $account, 'entries' => LoyaltyEntry::where('store_id', $id)->with('order')->latest('id')->paginate(15, ['*'], 'ledger_page')->withQueryString(), 'requests' => RewardRequest::where('store_id', $id)->with('reviewer')->latest('id')->paginate(10, ['*'], 'request_page')->withQueryString(), 'gifts' => $gifts, 'used' => $used, 'submissionKey' => (string) Str::uuid()]);
    }

    public function request(Request $r, Loyalty $loyalty)
    {
        $d = $r->validate(['submission_key' => ['required', 'uuid'], 'expected_points' => ['required', 'integer', 'min:1']]);
        $loyalty->request($r->user('merchant'), $d['submission_key'], (int) $d['expected_points']);

        return back()->with('status', 'أُرسل طلب الاستبدال للإدارة وحُجزت النقاط لحين المراجعة.');
    }

    public function settings(Loyalty $loyalty)
    {
        return view('delivery.points-settings', ['settings' => $loyalty->settings()]);
    }

    public function updateSettings(Request $r)
    {
        $d = $r->validate(['enabled' => ['required', 'boolean'], 'points_per_order' => ['required', 'integer', 'between:1,10000'], 'reward_points' => ['required', 'integer', 'between:1,1000000'], 'coupon_days' => ['required', 'integer', 'between:1,366'], 'include_free_orders' => ['required', 'boolean']]);
        DB::transaction(function () use ($d, $r) {
            $settings = LoyaltySetting::whereKey(1)->lockForUpdate()->firstOrFail();
            $settings->forceFill($d)->save();
            Audit::log($r->user('staff'), 'loyalty.settings', '1', $d);
        }, 3);

        return back()->with('status', 'تم حفظ القواعد للطلبات الجديدة. الأرصدة والكوبونات والطلبات المرسلة للمراجعة محفوظة.');
    }

    public function admin(Request $r)
    {
        $d = $r->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])], 'store_id' => ['nullable', 'integer'], 'request_id' => ['nullable', 'integer']]);
        $q = RewardRequest::with('store', 'coupon', 'reviewer')->when($d['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->when($d['store_id'] ?? null, fn ($q, $s) => $q->where('store_id', $s))->when($d['request_id'] ?? null, fn ($q, $s) => $q->whereKey($s));
        $ids = (clone $q)->pluck('store_id');

        return view('delivery.rewards', ['requests' => $q->orderByRaw("CASE WHEN status='pending' THEN 0 ELSE 1 END")->latest('id')->paginate(15)->withQueryString(), 'accounts' => LoyaltyAccount::whereIn('store_id', $ids)->get()->keyBy('store_id'), 'pending' => RewardRequest::where('status', 'pending')->count(), 'stores' => Store::orderBy('name')->get()]);
    }

    public function review(Request $r, RewardRequest $rewardRequest, Loyalty $loyalty)
    {
        $d = $r->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'review_note' => ['required', 'string', 'min:3', 'max:500']]);
        $loyalty->review($r->user('staff'), $rewardRequest, $d['decision'], $d['review_note']);

        return back()->with('status', $d['decision'] === 'approved' ? 'تمت الموافقة وتوليد كوبون توصيل مجاني وإشعار المتجر.' : 'تم رفض الطلب وتحرير النقاط وإشعار المتجر.');
    }

    public function account(Request $r, int $storeId)
    {
        $store = Store::findOrFail($storeId);

        return view('delivery.points-account', ['store' => $store, 'account' => LoyaltyAccount::where('store_id', $storeId)->first(), 'entries' => LoyaltyEntry::where('store_id', $storeId)->with('order')->latest('id')->paginate(25)]);
    }
}
