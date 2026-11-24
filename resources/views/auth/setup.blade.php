<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Siapkan usaha · R Kasir</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @vite('resources/js/app.js')
</head>
<body class="auth-page setup-page coop-auth-page">
    <div class="auth-card setup-card coop-auth-card">
        <a class="brand auth-brand" href="{{ route('setup') }}"><span class="brand-mark"><span>R</span></span><span class="brand-copy"><b>R Kasir</b><small>Koperasi & usaha lokal</small></span></a>
        <div class="eyebrow">LANGKAH PERTAMA</div>
        <h1>Kenalkan usaha kamu</h1>
        <p class="muted">Buat akun pemilik. Setelah itu, pemilik bisa menambahkan kasir dan anggota tim.</p>
        @if($errors->any())<div class="alert error"><div><b>Periksa data berikut:</b><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
        <form method="post" action="{{ route('setup.store') }}" class="form-stack">
            @csrf
            <div class="form-grid">
                <label>Nama pemilik<input name="owner_name" value="{{ old('owner_name') }}" required maxlength="120" autocomplete="name"></label>
                <label>Nama usaha<input name="business_name" value="{{ old('business_name') }}" required maxlength="160" placeholder="Contoh: Koperasi Mekar Bersama"></label>
                <label>Jenis usaha<select name="business_type" required><option value="">Pilih jenis usaha</option><option value="grocery" @selected(old('business_type')==='grocery')>Koperasi / kelontong</option><option value="food" @selected(old('business_type')==='food')>Makanan & minuman</option><option value="electronics" @selected(old('business_type')==='electronics')>Elektronik</option><option value="other" @selected(old('business_type')==='other')>Usaha lainnya</option></select></label>
                <label>Nomor HP<input name="phone" value="{{ old('phone') }}" required maxlength="32" autocomplete="tel" placeholder="08xx"></label>
                <label>Email <span class="optional">opsional</span><input type="email" name="email" value="{{ old('email') }}" maxlength="190" autocomplete="email"></label>
                <span class="form-spacer"></span>
                <label>Kata sandi<input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
                <label>Ulangi kata sandi<input type="password" name="password_confirmation" minlength="8" required autocomplete="new-password"></label>
            </div>
            <button class="btn btn-primary btn-block coop-auth-submit">Buat ruang usaha <span>→</span></button>
        </form>
        <p class="auth-foot">Sudah punya akun? <a href="{{ route('login') }}">Masuk di sini</a></p>
    </div>
    <div id="coop-showcase" data-mode="setup"></div>
</body>
</html>
