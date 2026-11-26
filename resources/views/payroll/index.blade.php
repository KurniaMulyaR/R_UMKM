@extends('layouts.app')
@section('title','Payroll')
@section('crumb','Payroll')
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); $label = ['draft' => 'Draft', 'posted' => 'Diposting', 'paid' => 'Dibayar']; @endphp
<div class="page-heading"><div><div class="eyebrow">DATA USAHA</div><h1>Payroll</h1><p>Gaji bulanan {{ $activeCount }} karyawan aktif.</p></div><a class="btn btn-secondary" href="{{ route('employees.index') }}">Karyawan</a></div>
<form method="post" action="{{ route('payroll.store') }}" class="card section-card" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">@csrf
    <label style="max-width:200px">Periode<input type="month" name="period" value="{{ now()->format('Y-m') }}" required></label>
    <button class="btn btn-primary">Buat draft payroll</button>
</form>
<div class="card section-card"><div class="table-wrap"><table>
    <thead><tr><th>PERIODE</th><th>KARYAWAN</th><th class="text-right">GAJI BERSIH</th><th>STATUS</th></tr></thead><tbody>
    @forelse($runs as $run)
        <tr><td><a class="td-main" href="{{ route('payroll.show', $run->id) }}">{{ $run->period_key }}</a></td><td>{{ $run->employee_count }}</td><td class="money text-right">{{ $rp($run->total_net) }}</td>
            <td><span class="status {{ $run->status === 'paid' ? '' : 'pending' }}">{{ $label[$run->status] ?? $run->status }}</span></td></tr>
    @empty<tr><td colspan="4"><div class="empty-state"><div class="empty-icon">▤</div><b>Belum ada payroll</b><span>Buat draft untuk periode berjalan.</span></div></td></tr>@endforelse
    </tbody></table></div></div>
@endsection
