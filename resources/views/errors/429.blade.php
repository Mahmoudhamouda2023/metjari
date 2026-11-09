@extends('layouts.base')
@section('title', 'خلّينا نحاول بعد قليل')
@section('body')
<main id="main" class="error-page">
    <x-brand/>
    <strong>429</strong>
    <h1>خلّينا نحاول بعد قليل</h1>
    @if(request()->routeIs('captain.remittances.store'))
        <p>تجاوزت حد محاولات إنشاء التوريد خلال دقيقة. لم تُنفّذ هذه المحاولة. افتح قائمة كشوفك للتحقق من أي كشف سابق، ثم انتظر دقيقة قبل إرسال طلب جديد عند الحاجة.</p>
        <a class="button button-primary" href="{{ route('captain.finance') }}">عرض كشوف التوريد <x-icon name="arrow"/></a>
    @else
        <p>وصلنا عدد كبير من المحاولات. انتظر قليلًا ثم حاول مجددًا.</p>
        <a class="button button-primary" href="{{ route('home') }}">الرئيسية <x-icon name="arrow"/></a>
    @endif
</main>
@endsection
