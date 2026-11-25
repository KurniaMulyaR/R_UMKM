@extends('layouts.app')
@section('title','Pengeluaran')
@section('crumb','Pengeluaran')
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); $maxCategory = max(1, (float) $byCategory->max('total')); @endphp
<div class="page-heading"><div><div class="eyebrow">KEUANGAN</div><h1>Pengeluaran</h1><p>Catat biaya operasional agar untung bersih bulan ini terlihat jelas.</p></div>
    <div style="display:flex;gap:10px;align-items:center"><form method="get"><input type="month" name="month" value="{{ $month }}" onchange="this.form.submit()" aria-label="Bulan"></form><button class="btn btn-primary" type="button" onclick="document.querySelector('#expense-form').classList.toggle('open')">＋ Catat pengeluaran</button></div></div>
<div class="feature-summary" style="grid-template-columns:repeat(4,minmax(0,1fr))">
    <div class="card feature-stat"><span class="feature-stat-icon ready-icon">Rp</span><div><small>Omzet {{ $monthLabel }}</small><b>{{ $rp($sales) }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon planned-icon">Rp</span><div><small>Untung kotor</small><b>{{ $rp($grossProfit) }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon partial-icon">Rp</span><div><small>Total pengeluaran</small><b>{{ $rp($total) }}</b></div></div>
    <div class="card feature-stat"><span class="feature-stat-icon {{ $netProfit >= 0 ? 'ready-icon' : 'partial-icon' }}">=</span><div><small>Untung bersih</small><b>{{ $netProfit < 0 ? '− ' : '' }}{{ $rp(abs($netProfit)) }}</b></div></div>
</div>
<div id="expense-form" class="card form-card product-form">
    <h2>Pengeluaran baru</h2>
    <form method="post" action="{{ route('expenses.store') }}">@csrf
        <div class="form-grid">
            <label>Kategori<select name="category">@foreach($categories as $code => $label)<option value="{{ $code }}" @selected(old('category') === $code)>{{ $label }}</option>@endforeach</select></label>
            <label>Nominal<input type="number" name="amount" required min="1" step="1" value="{{ old('amount') }}"></label>
            <label>Dibayar dari<select name="method"><option value="cash">Kas tunai</option><option value="bank_transfer" @selected(old('method') === 'bank_transfer')>Rekening bank</option></select></label>
            <label>Tanggal<input type="date" name="spent_at" required max="{{ now()->toDateString() }}" value="{{ old('spent_at', now()->toDateString()) }}"></label>
            <label>Dibayarkan ke <span class="optional">opsional</span><input name="payee" maxlength="160" value="{{ old('payee') }}"></label>
            <label>Keterangan <span class="optional">opsional</span><input name="description" maxlength="500" value="{{ old('description') }}"></label>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Simpan pengeluaran</button></div>
    </form>
</div>
<div class="dash-grid" style="grid-template-columns:minmax(0,1fr) minmax(0,1.8fr);align-items:start">
    <div class="card section-card"><div class="section-title"><h2>Per kategori</h2></div>
        @forelse($byCategory as $row)
            <div style="margin-bottom:14px"><div class="detail-row" style="border:0;padding:0 0 6px"><span>{{ $row->name }}</span><b>{{ $rp($row->total) }}</b></div><div style="height:8px;border-radius:99px;background:var(--surface-3)"><div style="height:8px;border-radius:99px;background:var(--primary);width:{{ max(3, round($row->total / $maxCategory * 100)) }}%"></div></div></div>
        @empty
            <span class="muted">Belum ada pengeluaran bulan ini.</span>
        @endforelse
    </div>
    <div class="card section-card"><div class="section-title"><h2>Daftar pengeluaran</h2><span class="status">{{ $expenses->count() }} catatan</span></div>
        <div class="table-wrap"><table>
            <thead><tr><th>TANGGAL</th><th>KATEGORI</th><th>KETERANGAN</th><th class="text-right">NOMINAL</th><th></th></tr></thead>
            <tbody>
            @forelse($expenses as $expense)
                <tr @if($expense->status === 'voided') style="opacity:.55" @endif>
                    <td>{{ \Illuminate\Support\Carbon::parse($expense->spent_at)->format('d M') }}<span class="td-sub">{{ $expense->expense_number }}</span></td>
                    <td class="td-main">{{ $expense->category_name }}</td>
                    <td>{{ $expense->payee ?: '—' }}<span class="td-sub">{{ $expense->description }}</span></td>
                    <td class="money text-right">{{ $expense->status === 'voided' ? '' : '' }}{{ $rp($expense->total) }}@if($expense->status === 'voided')<span class="td-sub">Dibatalkan</span>@endif</td>
                    <td>@if($expense->status === 'posted')<details><summary class="small-link">Batalkan</summary><form method="post" action="{{ route('expenses.void',$expense->id) }}" class="form-stack" style="margin-top:8px;min-width:200px">@csrf<input name="reason" required minlength="3" maxlength="500" placeholder="Alasan"><button class="btn btn-danger">Batalkan</button></form></details>@endif</td>
                </tr>
            @empty
                <tr><td colspan="5"><div class="empty-state"><div class="empty-icon">Rp</div><b>Belum ada pengeluaran</b><span>Catat sewa, listrik, gaji, atau biaya lain untuk melihat untung bersih.</span></div></td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>
</div>
@endsection
