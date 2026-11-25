@extends('layouts.app')
@section('title','Outlet')
@section('crumb','Outlet')
@section('content')
@php $isOwner = ($currentRole->key ?? '') === 'owner'; $activeId = request()->attributes->get('outlet')->id ?? null; @endphp
<div class="page-heading"><div><div class="eyebrow">DATA USAHA</div><h1>Outlet</h1><p>Kelola cabang, pindah outlet aktif, dan atur siapa yang boleh mengakses tiap outlet.</p></div></div>
@if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<div class="card section-card"><div class="section-title"><h2>Daftar outlet</h2></div>
    <table class="table"><thead><tr><th>Nama</th><th>Kode</th><th>Akses</th><th></th></tr></thead><tbody>
    @foreach($outlets as $outlet)
        @php $people = $access[$outlet->id] ?? collect(); @endphp
        <tr><td>{{ $outlet->name }} @if($outlet->id === $activeId)<span class="badge">Aktif</span>@endif</td><td>{{ $outlet->code }}</td>
            <td>
                @forelse($people as $person)
                    {{ $person->name }}
                    @if($isOwner)<form method="post" action="{{ route('outlets.revoke') }}" style="display:inline">@csrf<input type="hidden" name="outlet_id" value="{{ $outlet->id }}"><input type="hidden" name="tenant_user_id" value="{{ $person->tenant_user_id }}"><button class="small-link" style="background:none;border:0;cursor:pointer">cabut</button></form>@endif{{ ! $loop->last ? ',' : '' }}
                @empty <span class="muted">Semua staf tanpa batasan</span> @endforelse
            </td>
            <td>@if(in_array($outlet->id, $allowed, true) || $allowed === [])
                @if($outlet->id !== $activeId)<form method="post" action="{{ route('outlets.switch', $outlet->id) }}">@csrf<button class="btn btn-secondary">Pakai outlet ini</button></form>@endif
            @endif</td></tr>
        @if($isOwner)
        <tr><td colspan="4"><form method="post" action="{{ route('outlets.grant') }}" style="display:flex;gap:8px;align-items:center">@csrf<input type="hidden" name="outlet_id" value="{{ $outlet->id }}">
            <select name="tenant_user_id" required><option value="">Beri akses ke…</option>@foreach($staff as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach</select><button class="btn btn-secondary">Beri akses</button></form></td></tr>
        @endif
    @endforeach
    </tbody></table>
</div>
@if($isOwner)
<form method="post" action="{{ route('outlets.store') }}" class="card section-card" style="margin-top:16px">@csrf
    <div class="section-title"><h2>Tambah outlet</h2></div>
    <label>Nama outlet<input name="name" required maxlength="120" value="{{ old('name') }}"></label>
    <label>Kode <span class="optional">2–16 huruf/angka, dipakai di nomor dokumen</span><input name="code" required maxlength="16" value="{{ old('code') }}"></label>
    <label>Alamat <span class="optional">opsional</span><input name="address" maxlength="500" value="{{ old('address') }}"></label>
    <label>Telepon <span class="optional">opsional</span><input name="phone" maxlength="32" value="{{ old('phone') }}"></label>
    <div class="form-actions"><button class="btn btn-primary">Tambah outlet</button></div>
</form>
@endif
@endsection
