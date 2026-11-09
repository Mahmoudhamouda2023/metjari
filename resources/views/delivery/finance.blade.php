@extends('layouts.shell')
@php $pageTitle = ['overview'=>'الملخص المالي والمصروفات','stores'=>'تسويات المتاجر','captains'=>'تسويات الكباتن','remittances'=>'توريدات الكباتن'][$financeSection]; @endphp
@section('title', $pageTitle)
@section('content')
<div class="page-heading"><div><span class="eyebrow">المتابعة المالية</span><h1>{{ $pageTitle }}</h1><p>سجّل الدفع أو الاستلام الفعلي، ويتحدث الرصيد والسجل تلقائيًا.</p></div><a class="button button-secondary" href="{{ route('admin.finance.history', ['kind'=>['stores'=>'settlement','captains'=>'captain','remittances'=>'remittance'][$financeSection] ?? null]) }}">سجل التسويات الكامل</a></div>
<nav class="finance-page-links" aria-label="الصفحات المالية">
    @foreach(['overview'=>['admin.finance','الملخص المالي'],'stores'=>['admin.finance.stores','تسويات المتاجر الجاهزة'],'captains'=>['admin.finance.captains','تسويات الكباتن'],'remittances'=>['admin.finance.remittances','توريدات الكباتن']] as $section=>[$routeName,$label])
        <a class="button {{ $financeSection === $section ? 'button-primary' : 'button-secondary' }}" href="{{ route($routeName) }}" @if($financeSection === $section) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
<div data-finance-root data-live-url="{{ route('admin.finance.live', ['section'=>$financeSection,'page'=>request('page',1)]) }}">
    <div data-finance-feedback role="status" aria-live="polite"></div>
    @include('delivery.finance-content')
</div>
@endsection
@push('scripts')<script src="{{ asset('assets/finance.js') }}?v={{ config('delivery.release') }}" defer></script>@endpush
