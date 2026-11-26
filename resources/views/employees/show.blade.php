@extends('layouts.app')
@section('title', $employee->name)
@section('crumb','Karyawan')
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); $runLabel = ['draft' => 'Draft', 'posted' => 'Diposting', 'paid' => 'Dibayar']; @endphp
<div class="page-heading"><div><div class="eyebrow">KARYAWAN</div><h1>{{ $employee->name }}</h1><p>{{ $employee->position ?: 'Tanpa jabatan' }} · Gaji pokok {{ $rp($employee->base_salary) }}</p></div>
    <form method="post" action="{{ route('employees.status', [$employee->id, $employee->status === 'active' ? 'inactive' : 'active']) }}">@csrf<button class="btn btn-secondary">{{ $employee->status === 'active' ? 'Nonaktifkan' : 'Aktifkan kembali' }}</button></form></div>
<div class="card form-card"><h2>Data karyawan</h2>
    <form method="post" action="{{ route('employees.update', $employee->id) }}">@csrf @method('put')
        <div class="form-grid">
            <label>Nama<input name="name" required maxlength="160" value="{{ old('name', $employee->name) }}"></label>
            <label>Jabatan<input name="position" maxlength="120" value="{{ old('position', $employee->position) }}"></label>
            <label>No. HP<input name="phone" maxlength="32" value="{{ old('phone', $employee->phone) }}"></label>
            <label>Tanggal masuk<input type="date" name="joined_on" value="{{ old('joined_on', $employee->joined_on) }}"></label>
            <label>Tunjangan tetap<input type="number" name="fixed_allowance" min="0" step="1" value="{{ old('fixed_allowance', $employee->fixed_allowance) }}"></label>
            <label>Outlet<select name="outlet_id"><option value="">Semua outlet</option>@foreach($outlets as $o)<option value="{{ $o->id }}" @selected($employee->outlet_id === $o->id)>{{ $o->name }}</option>@endforeach</select></label>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Simpan</button></div>
    </form></div>
<div class="card form-card"><h2>Evaluasi baru</h2>
    <form method="post" action="{{ route('employees.reviews.store', $employee->id) }}">@csrf
        <div class="form-grid">
            <label>Tanggal<input type="date" name="reviewed_on" required value="{{ now()->toDateString() }}"></label>
            <label>Nilai (1-5)<input type="number" name="rating" required min="1" max="5" value="{{ old('rating', 3) }}"></label>
            <label>Gaji pokok baru <span class="optional">opsional</span><input type="number" name="new_salary" min="0" step="1" placeholder="{{ (int) $employee->base_salary }}"></label>
            <label>Penghargaan <span class="optional">opsional</span><input name="reward_note" maxlength="255"></label>
            <label>Catatan <span class="optional">opsional</span><input name="notes" maxlength="2000"></label>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Simpan evaluasi</button></div>
    </form></div>
<div class="card section-card"><h2>Riwayat evaluasi</h2><div class="table-wrap"><table>
    <thead><tr><th>TANGGAL</th><th>NILAI</th><th>GAJI</th><th>CATATAN</th></tr></thead><tbody>
    @forelse($reviews as $r)
        <tr><td>{{ \Illuminate\Support\Carbon::parse($r->reviewed_on)->format('d M Y') }}<span class="td-sub">{{ $r->reviewer_name }}</span></td><td>{{ $r->rating }}/5</td>
            <td>{{ $r->new_salary !== null ? $rp($r->previous_salary).' → '.$rp($r->new_salary) : 'Tetap' }}</td><td>{{ $r->reward_note }}{{ $r->reward_note && $r->notes ? ' · ' : '' }}{{ $r->notes }}</td></tr>
    @empty<tr><td colspan="4" class="muted">Belum ada evaluasi.</td></tr>@endforelse
    </tbody></table></div></div>
<div class="card section-card"><h2>Slip gaji</h2><div class="table-wrap"><table>
    <thead><tr><th>PERIODE</th><th>STATUS</th><th class="text-right">GAJI BERSIH</th><th></th></tr></thead><tbody>
    @forelse($slips as $s)
        <tr><td>{{ $s->period_key }}</td><td>{{ $runLabel[$s->run_status] ?? $s->run_status }}</td><td class="money text-right">{{ $rp($s->net_pay) }}</td>
            <td>@if($s->run_status !== 'draft')<a href="{{ route('payroll.slip', [$s->payroll_run_id, $s->id]) }}" target="_blank">Slip</a>@endif</td></tr>
    @empty<tr><td colspan="4" class="muted">Belum ada payroll.</td></tr>@endforelse
    </tbody></table></div></div>
@endsection
