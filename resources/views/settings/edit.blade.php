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
    </div>
    <div class="form-actions"><button class="btn btn-primary">Simpan</button></div>
</form>
@endsection
