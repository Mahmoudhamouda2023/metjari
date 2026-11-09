@if($order->returnTask)
<section class="panel return-panel section-space">
    <div class="panel-heading"><div><span class="eyebrow">مهمة إرجاع مرتبطة بنفس الطلب</span><h2>إرجاع الطرد إلى المتجر</h2></div><span class="badge">{{ $order->label }}</span></div>
    <p><strong>وجهة الإرجاع:</strong> {{ $order->pickup_snapshot }} · <a dir="ltr" href="tel:{{ $order->pickup_phone }}">{{ $order->pickup_phone }}</a></p>
    <p>كابتن التوصيل: {{ $order->captain?->name }} · كابتن الإرجاع: {{ $order->returnTask->captain?->name ?? 'بانتظار الإسناد' }}</p>
    <p class="muted">استلام الإرجاع من المكتب. مهمة إرجاع الطرد لا تتطلب تحصيل قيمة البضاعة من المتجر. يحصّل كابتن الإرجاع أجرة الإرجاع المحددة فقط من المتجر ويسجل المقبوض الفعلي، ثم يورّده للإدارة.</p>
    @if($order->returnTask->office_received_at)<p>أكد المكتب الاستلام: {{ $order->returnTask->receiver?->name }} · {{ $order->returnTask->office_received_at->timezone('Asia/Hebron')->format('Y/m/d H:i') }}</p>@endif
    @if($prefix === 'admin' && auth('staff')->user()->can('operate-delivery') && in_array($order->status,['office_received','return_assigned']))
    <form method="post" action="{{ route('admin.returns.assign',$order) }}" class="filter-form">@csrf<div class="field"><label for="return-captain">كابتن إرجاع الطرد</label><select id="return-captain" name="captain_id" required><option value="">اختر الكابتن</option>@foreach($captains as $c)<option value="{{ $c->id }}" @selected((int)$order->returnTask->captain_id===(int)$c->id)>{{ $c->name }}</option>@endforeach</select></div><button class="button button-primary">إسناد مهمة الإرجاع</button></form>
    @endif
</section>
@endif
