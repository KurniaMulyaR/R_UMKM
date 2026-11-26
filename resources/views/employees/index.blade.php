@extends('layouts.app')
@section('title','Karyawan')
@section('crumb','Karyawan')
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); @endphp
<div class="page-heading"><div><div class="eyebrow">DATA USAHA</div><h1>Karyawan</h1><p>Data karyawan, gaji pokok, dan evaluasi berkala.</p></div>
    <div style="display:flex;gap:8px"><a class="btn btn-secondary" href="{{ route('payroll.index') }}">Payroll</a><button class="btn btn-primary" type="button" onclick="document.querySelector('#employee-form').classList.toggle('open')">＋ Tambah karyawan</button></div></div>
<div id="employee-form" class="card form-card product-form">
    <h2>Karyawan baru</h2>
    <form method="post" action="{{ route('employees.store') }}">@csrf
        <div class="form-grid">
            <label>Nama<input name="name" required maxlength="160" value="{{ old('name') }}"></label>
            <label>Jabatan <span class="optional">opsional</span><input name="position" maxlength="120" value="{{ old('position') }}"></label>
            <label>No. HP <span class="optional">opsional</span><input name="phone" maxlength="32" value="{{ old('phone') }}"></label>
            <label>Tanggal masuk <span class="optional">opsional</span><input type="date" name="joined_on" value="{{ old('joined_on') }}"></label>
            <label>Gaji pokok<input type="number" name="base_salary" required min="0" step="1" value="{{ old('base_salary') }}"></label>
            <label>Tunjangan tetap <span class="optional">opsional</span><input type="number" name="fixed_allowance" min="0" step="1" value="{{ old('fixed_allowance', 0) }}"></label>
            <label>Outlet <span class="optional">opsional</span><select name="outlet_id"><option value="">Semua outlet</option>@foreach($outlets as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select></label>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Simpan</button></div>
    </form>
</div>
<div class="card section-card"><div class="table-wrap"><table>
    <thead><tr><th>KARYAWAN</th><th>OUTLET</th><th class="text-right">GAJI POKOK</th><th class="text-right">TUNJANGAN</th><th>STATUS</th></tr></thead>
    <tbody>
    @forelse($employees as $e)
        <tr><td><a class="td-main" href="{{ route('employees.show', $e->id) }}">{{ $e->name }}</a><span class="td-sub">{{ $e->position ?: 'Tanpa jabatan' }}</span></td>
            <td>{{ $e->outlet_name ?: 'Semua outlet' }}</td><td class="money text-right">{{ $rp($e->base_salary) }}</td><td class="money text-right">{{ $rp($e->fixed_allowance) }}</td>
            <td><span class="status {{ $e->status === 'active' ? '' : 'voided' }}">{{ $e->status === 'active' ? 'Aktif' : 'Nonaktif' }}</span></td></tr>
    @empty
        <tr><td colspan="5"><div class="empty-state"><div class="empty-icon">▤</div><b>Belum ada karyawan</b><span>Tambahkan karyawan untuk mulai menjalankan payroll.</span></div></td></tr>
    @endforelse
    </tbody></table></div></div>
@endsection
