@extends('layouts.app')
@section('title','Pengaturan usaha')
@section('crumb','Pengaturan usaha')
@section('content')
<div class="page-heading"><div><div class="eyebrow">TENANT</div><h1>Pengaturan usaha</h1><p>Identitas ini tampil di invoice, surat jalan, dan kwitansi.</p></div></div>
@if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('settings.update') }}" class="card form-card">@csrf @method('PUT')
    <div class="form-grid">
        <label>Nama usaha<input name="name" required maxlength="160" value="{{ old('name', $tenant->name) }}"></label>
        <label>Nama badan hukum <span class="optional">opsional, dipakai di dokumen</span><input name="legal_name" maxlength="200" value="{{ old('legal_name', $tenant->legal_name) }}"></label>
        <label>Telepon<input name="phone" maxlength="32" value="{{ old('phone', $tenant->phone) }}"></label>
        <label>Email<input type="email" name="email" maxlength="190" value="{{ old('email', $tenant->email) }}"></label>
        <label>Zona waktu<select name="timezone">@foreach($timezones as $zone => $label)<option value="{{ $zone }}" @selected(old('timezone', $tenant->timezone) === $zone)>{{ $label }}</option>@endforeach</select></label>
        <label>Catatan kaki dokumen <span class="optional">opsional</span><input name="document_footer" maxlength="300" placeholder="Contoh: Barang yang sudah dibeli tidak dapat ditukar." value="{{ old('document_footer', $settings['document_footer'] ?? '') }}"></label>
        <label>Lebar kertas struk<select name="receipt_width"><option value="80" @selected((int) old('receipt_width', $settings['receipt_width'] ?? 80) === 80)>80 mm</option><option value="58" @selected((int) old('receipt_width', $settings['receipt_width'] ?? 80) === 58)>58 mm</option></select></label>
    </div>
    <h2 style="margin-top:18px">PPN penjualan <span class="optional">opsional</span></h2>
    <p class="muted" style="margin:4px 0 12px">Aktifkan hanya jika usaha Anda PKP dan memungut PPN. Perubahan berlaku untuk transaksi baru; transaksi lama tidak berubah.</p>
    <div class="form-grid">
        <label style="flex-direction:row;gap:8px;align-items:center"><input type="checkbox" name="ppn_enabled" value="1" @checked(old('ppn_enabled', $settings['ppn_enabled'] ?? false))> Pungut PPN di kasir</label>
        <label>Tarif (%)<input type="number" name="ppn_rate" min="0.01" max="100" step="0.01" value="{{ old('ppn_rate', $settings['ppn_rate'] ?? 11) }}"></label>
        <label>Cara harga<select name="ppn_mode"><option value="exclusive" @selected(old('ppn_mode', $settings['ppn_mode'] ?? 'exclusive') === 'exclusive')>Harga belum termasuk PPN (PPN ditambahkan)</option><option value="inclusive" @selected(old('ppn_mode', $settings['ppn_mode'] ?? 'exclusive') === 'inclusive')>Harga sudah termasuk PPN</option></select></label>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Simpan</button></div>
</form>
@endsection
