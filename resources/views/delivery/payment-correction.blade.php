@extends('layouts.shell')
@section('title','تصحيح التحصيل')
@section('content')
<div class="page-heading"><div><h1>تصحيح تحصيل {{ $order->reference }}</h1><p>المطلوب تحصيله: {{ \App\Services\Delivery\Money::show($order->collect_minor) }}. تُحفظ البيانات السابقة في سجل التدقيق.</p></div><a class="button button-secondary" href="{{ route('admin.orders.show',$order) }}">العودة للطلب</a></div>
<section class="panel">
@if(!$order->entry || $order->entry->outcome!=='delivered')<p>التصحيح متاح للطلب المسلّم فقط.</p>
@elseif($order->entry->remittance_id || $order->entry->settlement_id || $order->entry->captain_payout_id)
<p class="finance-warning">توجد كشوف مالية مرتبطة. يجب إعادة الأموال وعكس الدفعات قبل تغيير التحصيل، للحفاظ على صحة الحسابات.</p><div class="actions">@foreach(['settlement'=>'settlement_id','captain'=>'captain_payout_id','remittance'=>'remittance_id'] as $kind=>$column)@if($order->entry->$column)<a class="button button-secondary" href="{{ route('admin.finance.receipt',['kind'=>$kind,'id'=>$order->entry->$column]) }}">{{ ['settlement'=>'تسوية المتجر','captain'=>'صرف الكابتن','remittance'=>'توريد الكابتن'][$kind] }} #{{ $order->entry->$column }}</a>@endif @endforeach</div>
@else
<form method="post" action="{{ route('admin.orders.payment.correct',$order) }}" class="form-stack">@csrf @method('PATCH')
@include('delivery.payment-fields',['financeCorrection'=>true,'correction'=>(bool)$order->payment])
<x-field name="correction_reason" label="سبب التصحيح ومرجع التحقق" required minlength="5" maxlength="500"/>
<button class="button button-primary">حفظ تصحيح التحصيل</button></form>
@endif</section>
@endsection
