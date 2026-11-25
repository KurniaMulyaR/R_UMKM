@extends('layouts.app')
@section('title','Daftar akun')
@section('crumb','Daftar akun')
@section('content')
@php $isOwner = ($currentRole->key ?? '') === 'owner'; @endphp
<div class="page-heading"><div><div class="eyebrow">PEMBUKUAN</div><h1>Daftar akun (COA)</h1><p>Akun sistem tidak bisa diubah. Tambah akun sendiri untuk jurnal manual dan import.</p></div></div>
@if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
@if($isOwner)
<form method="post" action="{{ route('accounts.store') }}" class="card section-card">@csrf
    <div class="form-grid">
        <label>Kode (4–8 angka)<input name="code" required pattern="\d{4,8}" value="{{ old('code') }}"></label>
        <label>Nama akun<input name="name" required maxlength="160" value="{{ old('name') }}"></label>
        <label>Jenis<select name="account_type">@foreach($types as $key => $label)<option value="{{ $key }}" @selected(old('account_type') === $key)>{{ $label }}</option>@endforeach</select></label>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Tambah akun</button></div>
</form>
@endif
<div class="card section-card"><table class="table"><thead><tr><th>Kode</th><th>Nama</th><th>Jenis</th><th>Saldo normal</th><th>Sumber</th></tr></thead><tbody>
    @foreach($accounts as $account)<tr><td>{{ $account->code }}</td><td>{{ $account->name }}</td><td>{{ $types[$account->account_type] ?? $account->account_type }}</td><td>{{ $account->normal_balance === 'debit' ? 'Debit' : 'Kredit' }}</td><td>{{ $account->is_system ? 'Sistem' : 'Manual' }}</td></tr>@endforeach
</tbody></table></div>
@endsection
