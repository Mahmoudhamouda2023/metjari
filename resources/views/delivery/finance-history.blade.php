@extends('layouts.shell')
@section('title', 'سجل التسويات والتوريدات')
@section('content')
<div class="page-heading"><div><span class="eyebrow">المالية</span><h1>سجل التسويات والتوريدات</h1><p>جميع العمليات مع المراجع الأصلية وحالة كل كشف.</p></div><a class="button button-secondary" href="{{ route('admin.finance') }}">العودة للتسويات</a></div>
<section class="panel">
    <form method="get" class="filter-form">
        <div class="field"><label for="reference-kind">فئة المرجع</label><select name="kind" id="reference-kind">
            <option value="">جميع الفئات</option>
            @foreach(['settlement' => 'STL — تسوية متجر', 'captain' => 'PAY — صرف كابتن', 'remittance' => 'REM — توريد كابتن'] as $value => $label)
                <option value="{{ $value }}" @selected(request('kind') === $value)>{{ $label }}</option>
            @endforeach
        </select></div>
        <x-field name="q" label="المرجع أو اسم المتجر / الكابتن" :value="request('q')" maxlength="160"/>
        <button class="button button-primary">بحث</button>
    </form>
    @include('delivery.finance-history-table')
    {{ $history->links() }}
</section>
@endsection
