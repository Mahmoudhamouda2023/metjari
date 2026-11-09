@extends('layouts.shell')
@section('title', 'الطلبات')
@section('breadcrumb', 'الطلبات')
@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">تشغيل التوصيل</span>
            <h1>الطلبات</h1>
            <p>كل طلب، وحالته، وتفاصيل متابعته.</p>
        </div>
        <div class="actions">
            @if ($prefix === 'merchant')
                <a class="button button-primary" href="{{ route('merchant.orders.create') }}">طلب توصيل جديد +</a>
                @endif @if ($prefix !== 'captain')
                    <a class="button button-secondary"
                        href="{{ route($prefix . '.orders.export', request()->query()) }}">تصدير
                        CSV</a>
                @endif
        </div>
    </div>
    <section class="panel">
        <form class="filter-form" method="get"><x-field name="q" label="ابحث باسم المتجر أو رقم الطلب أو المستلم"
                :value="request('q')" maxlength="100" />
            <div class="field"><label for="origin">الجهة التي أنشأت الطلب</label><select id="origin" name="origin"><option value="">جميع الجهات</option>@foreach(['merchant'=>'المتجر — M','admin'=>'الإدارة — A','captain'=>'الكابتن — C'] as $v=>$label)<option value="{{ $v }}" @selected(request('origin')===$v)>{{ $label }}</option>@endforeach</select></div>
            <div class="field"><label for="reference_kind">فئة المرجع</label><select id="reference_kind" name="reference_kind"><option value="">جميع المراجع</option><option value="order" @selected(request('reference_kind')==='order')>رقم الطلب</option><option value="delivery" @selected(request('reference_kind')==='delivery')>DLV — إيصال تسليم</option></select></div>
            <div class="field"><label for="status">الحالة</label><select id="status" name="status">
                    <option value="">جميع الحالات</option>
                    @foreach (config('delivery.order_statuses') as $key => $label)
                        <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div><x-field name="from" label="من تاريخ" type="date" :value="request('from')" /><x-field name="to"
                label="إلى تاريخ" type="date" :value="request('to')" /><button class="button button-primary">عرض
                النتائج</button>
        </form>
        <div data-live-region="orders"><div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>الطلب / المرجع</th>
                        <th>المتجر والمستلم</th>
                        <th>الحالة</th>
                        <th>التحصيل / رسوم الإرجاع</th>
                        <th>التاريخ</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($orders as $o)
                        <tr>
                            <td><a class="text-link"
                                    href="{{ route($prefix . '.orders.show', $o) }}"><bdi>{{ $o->reference }}</bdi></a><small
                                    class="cell-note">{{ $o->merchant_reference }}</small></td>
                            <td>{{ $o->store->name }}<small class="cell-note">{{ $o->recipient_name }} ·
                                    {{ $o->destination_snapshot }}</small></td>
                            <td><span class="badge">{{ $o->label }}</span><small
                                    class="cell-note">{{ $o->captain?->name }}</small></td>
                            <td>
                                @if(in_array($o->status, ['failed','office_pending','office_received','return_assigned','return_picked_up','return_transit','returned']))
                                    {{ \App\Services\Delivery\Money::show($o->status === 'failed' ? \App\Services\Delivery\ServiceFees::attemptFee($o) : $o->return_fee_minor) }} ₪
                                    <small class="cell-note">{{ $o->status === 'failed' ? 'أجرة المحاولة من المستلم' : 'أجرة الإرجاع من المتجر' }} · ثمن البضاعة 0</small>
                                @elseif($o->status === 'cancelled')
                                    0.00 ₪
                                @else
                                    {{ \App\Services\Delivery\Money::show($o->collect_minor) }}
                                @endif
                            </td>
                            <td>{{ $o->created_at->timezone('Asia/Hebron')->format('m/d H:i') }}<small
                                    class="cell-note">توصيل: {{ $o->delivery_time_label }}</small></td>
                    </tr>@empty<tr>
                            <td colspan="5">
                                <div class="empty-state">لا توجد طلبات مطابقة.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>{{ $orders->links() }}</div>
    </section>
@endsection
