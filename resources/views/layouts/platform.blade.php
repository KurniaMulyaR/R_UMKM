<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Panel master') · R Kasir</title>
    @include('partials.theme-script')
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <header class="platform-bar">
        <a class="platform-brand" href="{{ route('platform.dashboard') }}">R Kasir <span>Panel master</span></a>
        <nav class="platform-nav">
            <a href="{{ route('platform.dashboard') }}" @class(['active' => request()->routeIs('platform.dashboard')])>Ringkasan</a>
            <a href="{{ route('platform.tenants') }}" @class(['active' => request()->routeIs('platform.tenants*')])>Usaha</a>
            <a href="{{ route('platform.tickets.index') }}" @class(['active' => request()->routeIs('platform.tickets.*')])>Tiket</a>
            <a href="{{ route('platform.logs') }}" @class(['active' => request()->routeIs('platform.logs')])>Log</a>
        </nav>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-secondary" type="submit">Keluar</button></form>
    </header>
    <main class="platform-main">
        @if(session('success'))<div class="alert success">{{ session('success') }}<button onclick="this.parentElement.remove()">×</button></div>@endif
        @if($errors->any())<div class="alert error"><div><b>Periksa lagi datanya:</b><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div><button onclick="this.parentElement.remove()">×</button></div>@endif
        @yield('content')
    </main>
</body>
</html>
