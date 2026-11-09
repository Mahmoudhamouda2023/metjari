@php
    $isAttempt = $order->status === 'failed';
    $stageFee = $isAttempt ? \App\Services\Delivery\ServiceFees::attemptFee($order) : $order->return_fee_minor;
    $serviceEntries = $order->financialEntries->where('fee_collection_required', true);
@endphp
<section class="panel">
    <h2>{{ $isAttempt ? 'أجرة محاولة التسليم' : 'أجرة إرجاع الطرد للمتجر' }}</h2>
    <p class="hint">ثمن البضاعة المطلوب تحصيله صفر. رسوم محاولة التسليم والإرجاع مرحلتان منفصلتان، ولكل مرحلة تحصيلها وتوريدها وأجرة كابتنها.</p>
    <dl class="details-list">
        <div><dt>قيمة البضاعة المطلوب تحصيلها</dt><dd data-goods-due>0.00 ₪</dd></div>
        <div><dt>أجرة هذه المرحلة</dt><dd>{{ $money::show($stageFee) }} ₪</dd></div>
        <div><dt>تُحصّل من</dt><dd>{{ $isAttempt ? 'المستلم' : 'المتجر' }}</dd></div>
        <div><dt>كابتن هذه المرحلة</dt><dd>{{ $isAttempt ? $order->captain?->name : ($order->returnTask?->captain?->name ?? 'بانتظار الإسناد') }}</dd></div>
    </dl>
    @if(!$order->service_fee_workflow)
        <p class="notice">هذا الطلب يتبع الحسابات السابقة. رسومه المسجلة: {{ $money::show($order->entry?->fee_minor ?? ($order->fee_minor + $order->return_fee_minor)) }} ₪. راجع كشفه قبل أي تحصيل جديد.</p>
    @endif
    @foreach($serviceEntries as $feeEntry)
        <div class="review-note">
            <strong>{{ $feeEntry->stage_label }}</strong>
            <p>المطلوب: {{ $money::show($feeEntry->fee_minor) }} ₪ · المقبوض فعليًا: {{ $money::show($feeEntry->collected_minor) }} ₪</p>
            <p>{{ $feeEntry->fee_collected_at ? ($feeEntry->remittance?->status === 'approved' ? 'تم التحصيل والتوريد للإدارة' : 'تم التحصيل — في عهدة الكابتن حتى اعتماد التوريد') : 'لم تُحصّل الرسوم — لا تدخل في نقد الكابتن أو صرف أجرته بعد' }}</p>
            @if($prefix === 'captain' && (int) $feeEntry->captain_id === (int) auth('staff')->id() && !$feeEntry->fee_collected_at)
                <form method="post" action="{{ route('captain.service-fees.collect', ['order' => $order, 'entry' => $feeEntry->id]) }}" class="form-stack">
                    @csrf
                    <x-field name="collected_amount" label="رسوم استلمتها نقديًا ولم تُسجّل من قبل ₪" type="number" min="0" step="0.01" required/>
                    <label><input type="checkbox" name="cash_received" value="1" required> أؤكد استلام هذه الأجرة من {{ $feeEntry->service_fee_payer === 'recipient' ? 'المستلم' : 'المتجر' }} فعلًا، دون ثمن البضاعة.</label>
                    <button class="button button-primary">تسجيل استلام الأجرة</button>
                </form>
            @endif
        </div>
    @endforeach
</section>
