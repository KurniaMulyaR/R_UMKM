@extends('layouts.app')
@section('title','Tim')
@section('crumb','Tim')
@section('content')
<div class="page-heading"><div><div class="eyebrow">AKSES PENGGUNA</div><h1>Tim usaha</h1><p>Tambahkan akun kasir atau staf. Kata sandi awal dibagikan langsung ke orangnya.</p></div></div>
@if($currentRole->key === 'owner')
<div class="card form-card"><h2>Tambah akun staf</h2><form method="post" action="{{ route('staff.store') }}">@csrf<div class="form-grid"><label>Nama lengkap<input name="name" value="{{ old('name') }}" required maxlength="120"></label><label>Nomor HP untuk masuk<input name="phone" value="{{ old('phone') }}" required maxlength="32" placeholder="08xx"></label><label>Email <span class="optional">opsional</span><input type="email" name="email" value="{{ old('email') }}" maxlength="190"></label><label>Peran<select name="role" required><option value="cashier">Kasir</option><option value="warehouse">Admin gudang</option><option value="manager">Manajer</option></select></label><label>Kata sandi awal<input type="password" name="password" minlength="8" required autocomplete="new-password"></label><label>Ulangi kata sandi<input type="password" name="password_confirmation" minlength="8" required autocomplete="new-password"></label></div><div class="form-actions"><button class="btn btn-primary">Buat akun staf</button></div></form></div>
@endif
@if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
@php $isOwner = ($currentRole->key ?? '') === 'owner'; $roleOptions = ['cashier' => 'Kasir', 'warehouse' => 'Admin gudang', 'manager' => 'Manajer']; @endphp
<div class="card section-card"><div class="section-title"><h2>Pengguna</h2><span class="status">{{ $staff->count() }} akun</span> <a class="small-link" href="{{ route('access.matrix') }}">Lihat hak akses per peran</a></div>
<div class="table-wrap"><table><thead><tr><th>NAMA</th><th>NOMOR HP</th><th>EMAIL</th><th>PERAN</th><th>STATUS</th><th>TERAKHIR MASUK</th></tr></thead><tbody>
@forelse($staff as $person)
    <tr @if($person->status !== 'active') style="opacity:.6" @endif><td class="td-main">{{ $person->name }}</td><td>{{ $person->phone }}</td><td>{{ $person->email ?: '—' }}</td><td>{{ $person->role_name }}</td><td>{{ $person->status === 'active' ? 'Aktif' : 'Nonaktif' }}</td><td>{{ $person->last_login_at ? \Illuminate\Support\Carbon::parse($person->last_login_at)->format('d M Y H:i') : 'Belum pernah' }}</td></tr>
    @if($isOwner && $person->role_key !== 'owner' && $person->id !== auth()->id())
    <tr><td colspan="6"><details><summary class="small-link">Kelola {{ $person->name }}</summary>
        <form method="post" action="{{ route('staff.update', $person->id) }}" style="margin:10px 0">@csrf @method('PUT')<div class="form-grid">
            <label>Nama<input name="name" value="{{ $person->name }}" required maxlength="120"></label><label>Nomor HP<input name="phone" value="{{ $person->phone }}" required maxlength="32"></label>
            <label>Email<input type="email" name="email" value="{{ $person->email }}" maxlength="190"></label>
            <label>Peran<select name="role">@foreach($roleOptions as $key => $label)<option value="{{ $key }}" @selected($person->role_key === $key)>{{ $label }}</option>@endforeach</select></label></div>
            <div class="form-actions"><button class="btn btn-secondary">Simpan perubahan</button></div></form>
        <form method="post" action="{{ route('staff.password', $person->id) }}" style="margin:10px 0">@csrf<div class="form-grid">
            <label>Kata sandi baru<input type="password" name="password" minlength="8" required autocomplete="new-password"></label><label>Ulangi kata sandi<input type="password" name="password_confirmation" minlength="8" required autocomplete="new-password"></label></div>
            <div class="form-actions"><button class="btn btn-secondary">Ganti kata sandi</button></div></form>
        <form method="post" action="{{ route('staff.status', [$person->id, $person->status === 'active' ? 'inactive' : 'active']) }}" onsubmit="return confirm('Yakin?')">@csrf<button class="btn btn-secondary">{{ $person->status === 'active' ? 'Nonaktifkan akun' : 'Aktifkan kembali' }}</button></form>
    </details></td></tr>
    @endif
@empty
    <tr><td colspan="6" class="muted">Belum ada pengguna.</td></tr>
@endforelse
</tbody></table></div></div>
@endsection
