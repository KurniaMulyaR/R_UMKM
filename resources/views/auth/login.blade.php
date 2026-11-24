<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Masuk · R Kasir</title>
    @include('partials.theme-script')
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @vite('resources/js/app.js')
</head>
<body class="auth-page coop-auth-page">
    <div class="auth-card coop-auth-card">
        <a class="brand auth-brand" href="{{ route('login') }}"><span class="brand-mark"><span>R</span></span><span class="brand-copy"><b>R Kasir</b><small>Koperasi & usaha lokal</small></span></a>
        <div class="eyebrow">SENANG BERTEMU LAGI</div>
        <h1>Masuk ke ruang usahamu</h1>
        <p class="muted">Catatan kas, stok, dan penjualan ada di satu tempat.</p>
        @if(session('setup_locked'))<div class="alert success">{{ session('setup_locked') }}</div>@endif
        @if($errors->any())<div class="alert error"><div>@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div></div>@endif
        <form method="post" action="{{ route('login.store') }}" class="form-stack">
            @csrf
            <label>Nomor HP atau email<input name="identity" value="{{ old('identity') }}" autocomplete="username" required autofocus placeholder="08xx atau nama@email.com"></label>
            <label>Kata sandi<input type="password" name="password" autocomplete="current-password" required></label>
            <label class="check-row"><input type="checkbox" name="remember" value="1"> Ingat saya di perangkat ini</label>
            <button class="btn btn-primary btn-block coop-auth-submit">Masuk <span>→</span></button>
        </form>
        <p class="auth-foot">Perlu akun staf? <span>Minta pemilik usaha membuatkannya.</span></p>
    </div>
    <div id="coop-showcase" data-mode="login"></div>
    @include('partials.theme-toggle-auth')
</body>
</html>
