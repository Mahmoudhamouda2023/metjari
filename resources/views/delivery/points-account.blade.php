@extends('layouts.shell')
@section('title','كشف نقاط المتجر')
@section('content')
<div class="page-heading"><div><h1>كشف نقاط {{ $store->name }}</h1><p>الرصيد {{ $account?->balance ?? 0 }} · المحجوز {{ $account?->reserved ?? 0 }} · المتاح {{ $account?->available ?? 0 }}</p></div><a href="{{ route('admin.rewards.index',['store_id'=>$store->id]) }}" class="button button-secondary">طلبات استبدال المتجر</a></div><section class="panel">@include('delivery.points-ledger'){{ $entries->links() }}</section>
@endsection
