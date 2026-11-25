<!doctype html>
@php
    $sidebarLinks = [
        ['group' => 'RINGKASAN', 'label' => 'Ringkasan', 'href' => route('dashboard'), 'icon' => 'home', 'active' => request()->routeIs('dashboard')],
        ['group' => 'OPERASIONAL', 'label' => 'Kasir', 'href' => route('pos.index'), 'icon' => 'pos', 'active' => request()->routeIs('pos.*'), 'shortcut' => 'F2'],
        ['group' => 'OPERASIONAL', 'label' => 'Penjualan', 'href' => route('sales.index'), 'icon' => 'sales', 'active' => request()->routeIs('sales.*')],
        ['group' => 'OPERASIONAL', 'label' => 'Shift kasir', 'href' => route('shifts.index'), 'icon' => 'clock', 'active' => request()->routeIs('shifts.*')],
        ['group' => 'DATA USAHA', 'label' => 'Produk & persediaan', 'href' => route('products.index'), 'icon' => 'box', 'active' => request()->routeIs('products.*')],
        ['group' => 'DATA USAHA', 'label' => 'Anggota & pelanggan', 'href' => route('customers.index'), 'icon' => 'users', 'active' => request()->routeIs('customers.*')],
    ];
    if (in_array($currentRole->key ?? '', ['owner', 'manager'], true)) {
        $sidebarLinks[] = ['group' => 'DATA USAHA', 'label' => 'Aset usaha', 'href' => route('assets.index'), 'icon' => 'wallet', 'active' => request()->routeIs('assets.*')];
        $sidebarLinks[] = ['group' => 'DATA USAHA', 'label' => 'Tim usaha', 'href' => route('staff.index'), 'icon' => 'team', 'active' => request()->routeIs('staff.*')];
    }
    if (in_array($currentRole->key ?? '', ['owner', 'manager', 'warehouse'], true)) {
        $sidebarLinks[] = ['group' => 'PEMBELIAN', 'label' => 'Pesanan beli', 'href' => route('purchasing.index'), 'icon' => 'receipt', 'active' => request()->routeIs('purchasing.index', 'purchasing.create', 'purchasing.show')];
        $sidebarLinks[] = ['group' => 'PEMBELIAN', 'label' => 'Supplier', 'href' => route('purchasing.suppliers'), 'icon' => 'wallet', 'active' => request()->routeIs('purchasing.suppliers')];
    }
    $sidebarLinks[] = ['group' => 'PETA PRODUK', 'label' => 'Fitur dalam PRD', 'href' => route('features.index'), 'icon' => 'grid', 'active' => request()->routeIs('features.*')];
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
            'userName' => auth()->user()->name ?? '',
            'lowStockCount' => (int) trim(strip_tags($__env->yieldContent('low_stock', '0'))),
            'productsUrl' => route('products.index'),
        ],
    ];
@endphp
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · R Kasir</title>
    @include('partials.theme-script')
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
    @stack('scripts')
</body>
</html>
