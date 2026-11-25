@extends('layouts.app')
@section('title','Rekonsiliasi '.$account->code)
@section('crumb','Rekonsiliasi')
@section('content')
@php $rp = fn ($n) => ($n < 0 ? '− ' : '').'Rp '.number_format(abs($n), 0, ',', '.'); $draft = $rec->status === 'draft'; @endphp
<div class="page-heading"><div><div class="eyebrow">REKONSILIASI</div><h1>{{ $account->code }} · {{ $account->name }}</h1><p>Per {{ \Illuminate\Support\Carbon::parse($rec->statement_date)->format('d/m/Y') }} · {{ $draft ? 'Draf' : 'Selesai' }}</p></div></div>
@if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<div class="feature-summary" style="grid-template-columns:repeat(4,minmax(0,1fr))">
    <div class="card feature-stat"><span class="feature-stat-icon planned-icon">Rp</span><div><small>Saldo rekening</small><b>{{ $rp($rec->statement_balance) }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon ready-icon">Rp</span><div><small>Saldo buku (per tanggal)</small><b>{{ $rp($bookBalance) }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon ready-icon">✓</span><div><small>Sudah dicocokkan</small><b>{{ $rp($cleared) }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon {{ abs($difference) < 0.01 ? 'ready-icon' : 'partial-icon' }}">=</span><div><small>Selisih</small><b>{{ $rp($difference) }}</b></div></div>
</div>
<form method="post" action="{{ route('reconciliations.update', $rec->id) }}" class="card section-card">@csrf @method('PUT')
    <table class="table"><thead><tr>@if($draft)<th></th>@endif<th>Tanggal</th><th>Nomor</th><th>Keterangan</th><th>Masuk</th><th>Keluar</th></tr></thead><tbody>
    @forelse($candidates as $line)
        @continue(! $draft && ! in_array($line->id, $selected, true))
        <tr>@if($draft)<td><input type="checkbox" name="lines[]" value="{{ $line->id }}" @checked(in_array($line->id, $selected, true))></td>@endif
            <td>{{ \Illuminate\Support\Carbon::parse($line->entry_date)->format('d/m/Y') }}</td><td>{{ $line->entry_number }}</td><td>{{ $line->description }}</td>
            <td>{{ (float) $line->debit > 0 ? $rp($line->debit) : '' }}</td><td>{{ (float) $line->credit > 0 ? $rp($line->credit) : '' }}</td></tr>
    @empty<tr><td colspan="6" class="muted">Tidak ada mutasi yang belum dicocokkan.</td></tr>@endforelse
    </tbody></table>
    @if($draft)
    <div class="form-actions"><button class="btn btn-secondary">Simpan pilihan</button> <button class="btn btn-primary" name="complete" value="1" @disabled(abs($difference) > 0.004)>Selesaikan</button></div>
    @endif
</form>
@if($draft)<form method="post" action="{{ route('reconciliations.destroy', $rec->id) }}">@csrf @method('DELETE')<button class="btn btn-danger">Hapus draf</button></form>@endif
@endsection
