@extends('layouts.app')
@section('title','Rekonsiliasi')
@section('crumb','Rekonsiliasi')
@section('content')
@php $rp = fn ($n) => ($n < 0 ? '− ' : '').'Rp '.number_format(abs($n), 0, ',', '.'); @endphp
<div class="page-heading"><div><div class="eyebrow">KEUANGAN</div><h1>Rekonsiliasi kas &amp; bank</h1><p>Cocokkan catatan kas/bank dengan mutasi rekening atau hitungan fisik.</p></div></div>
@if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('reconciliations.store') }}" class="card section-card">@csrf
    <div class="form-grid">
        <label>Akun<select name="account_id">@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(old('account_id') === $account->id)>{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></label>
        <label>Tanggal rekening koran<input type="date" name="statement_date" required max="{{ now()->toDateString() }}" value="{{ old('statement_date', now()->toDateString()) }}"></label>
        <label>Saldo akhir menurut rekening<input type="number" step="0.01" name="statement_balance" required value="{{ old('statement_balance') }}"></label>
        <label>Catatan <span class="optional">opsional</span><input name="note" maxlength="300" value="{{ old('note') }}"></label>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Mulai rekonsiliasi</button></div>
</form>
<div class="card section-card"><div class="section-title"><h2>Riwayat</h2></div>
<table class="table"><thead><tr><th>Akun</th><th>Tanggal</th><th>Saldo rekening</th><th>Status</th><th></th></tr></thead><tbody>
    @forelse($history as $row)
    <tr><td>{{ $row->code }} · {{ $row->account_name }}</td><td>{{ \Illuminate\Support\Carbon::parse($row->statement_date)->format('d/m/Y') }}</td><td>{{ $rp($row->statement_balance) }}</td><td>{{ $row->status === 'completed' ? 'Selesai' : 'Draf' }}</td><td><a class="small-link" href="{{ route('reconciliations.show', $row->id) }}">{{ $row->status === 'completed' ? 'Lihat' : 'Lanjutkan' }}</a></td></tr>
    @empty<tr><td colspan="5" class="muted">Belum ada rekonsiliasi.</td></tr>@endforelse
</tbody></table></div>
@endsection
