@extends('layouts.app')
@section('title','Pembayaran QRIS')
@section('crumb','Pembayaran QRIS')
@section('content')
<div class="page-heading"><div><div class="eyebrow">PEMBAYARAN</div><h1>QRIS Xendit</h1><p>Kasir membuat QR dinamis, pelanggan membayar, dan transaksi tersimpan otomatis setelah Xendit mengonfirmasi.</p></div></div>
@if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<div class="detail-grid">
    <form method="post" action="{{ route('payment-gateway.update') }}" class="card section-card form-stack">@csrf @method('PUT')
        <label>Secret API key <span class="optional">{{ $hasKeys ? 'tersimpan, kosongkan jika tidak diganti' : 'wajib' }}</span><input type="password" name="secret_key" autocomplete="off" placeholder="xnd_production_..."></label>
        <label>Callback verification token <span class="optional">{{ $hasKeys ? 'tersimpan' : 'wajib' }}</span><input type="password" name="callback_token" autocomplete="off"></label>
        <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" value="1" @checked($setting && $setting->is_active)> Aktifkan QRIS Xendit di kasir</label>
        <button class="btn btn-primary">Simpan</button>
    </form>
    <div class="card section-card"><div class="section-title"><h2>Cara menghubungkan</h2></div>
        <p class="muted">1. Di Dashboard Xendit buat Secret API key dengan izin Money-In Write dan Read.<br>2. Di Settings &gt; Callbacks isi URL berikut untuk "QR code paid &amp; refunded", lalu salin verification token ke form ini.<br>3. Uji dulu dengan kunci mode test.</p>
        <input readonly value="{{ $webhookUrl }}" onclick="this.select()" aria-label="URL webhook">
        <p class="muted" style="margin-top:10px">Kunci disimpan terenkripsi. Dana masuk ke akun Xendit usaha ini, bukan ke akun platform.</p>
    </div>
</div>
@endsection
