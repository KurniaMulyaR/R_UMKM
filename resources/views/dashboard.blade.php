@extends('layouts.app')
@section('title','Ringkasan')
@section('crumb','Ringkasan')
@section('low_stock', $lowStock->count())
@section('content')
@php
    $dashBootstrap = $overview + [
        'userName' => auth()->user()->name,
        'dateLabel' => now($currentTenant->timezone)->locale('id')->translatedFormat('l, d F Y'),
    ];
@endphp
<div id="dashboard-app"></div>
<script id="dashboard-bootstrap" type="application/json">@json($dashBootstrap)</script>
<noscript>Dashboard membutuhkan JavaScript aktif.</noscript>
@endsection
