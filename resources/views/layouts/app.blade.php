<!doctype html>
@php
    $sidebarLinks = [
        ['group' => 'RINGKASAN', 'label' => 'Ringkasan', 'href' => route('dashboard'), 'icon' => '⌂', 'active' => request()->routeIs('dashboard')],
        ['group' => 'OPERASIONAL', 'label' => 'Kasir', 'href' => route('pos.index'), 'icon' => '▦', 'active' => request()->routeIs('pos.*'), 'shortcut' => 'F2'],
        ['group' => 'OPERASIONAL', 'label' => 'Penjualan', 'href' => route('sales.index'), 'icon' => '↗', 'active' => request()->routeIs('sales.*')],
        ['group' => 'OPERASIONAL', 'label' => 'Shift kasir', 'href' => route('shifts.index'), 'icon' => '◷', 'active' => request()->routeIs('shifts.*')],
        ['group' => 'DATA USAHA', 'label' => 'Produk & persediaan', 'href' => route('products.index'), 'icon' => '□', 'active' => request()->routeIs('products.*')],
        ['group' => 'DATA USAHA', 'label' => 'Anggota & pelanggan', 'href' => route('customers.index'), 'icon' => '♙', 'active' => request()->routeIs('customers.*')],
    ];
    if (in_array($currentRole->key ?? '', ['owner', 'manager'], true)) {
        $sidebarLinks[] = ['group' => 'DATA USAHA', 'label' => 'Tim usaha', 'href' => route('staff.index'), 'icon' => '♧', 'active' => request()->routeIs('staff.*')];
    }
    $sidebarLinks[] = ['group' => 'PETA PRODUK', 'label' => 'Fitur dalam PRD', 'href' => route('features.index'), 'icon' => '▤', 'active' => request()->routeIs('features.*')];
    $ui = [
        'sidebar' => [
            'businessName' => $currentTenant->name ?? 'Usaha bersama',
            'outletName' => $currentOutlet->name ?? 'Outlet utama',
            'userName' => auth()->user()->name ?? 'Pengguna',
            'roleName' => $currentRole->name ?? 'Anggota',
            'logoutUrl' => route('logout'),
            'logoutToken' => csrf_token(),
            'links' => $sidebarLinks,
        ],
        'topbar' => [
            'crumb' => trim(strip_tags($__env->yieldContent('crumb', 'Ringkasan'))),
            'dateLabel' => now()->locale('id')->translatedFormat('l, d F Y'),
            'links' => $sidebarLinks,
        ],
    ];
@endphp
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · R Kasir</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @vite('resources/js/app.js')
    @stack('head')
</head>
<body>
    <div class="app-shell">
        <div id="coop-sidebar"></div>
        <main class="main-area">
            <div id="coop-topbar"></div>
            <section class="page-content">
                @if(session('success'))<div class="alert success">{{ session('success') }}<button onclick="this.parentElement.remove()">×</button></div>@endif
                @if($errors->any())<div class="alert error"><div><b>Periksa lagi datanya:</b><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div><button onclick="this.parentElement.remove()">×</button></div>@endif
                @yield('content')
            </section>
        </main>
    </div>
    <script>window.rKasirUi = @json($ui);</script>
</body>
</html>
