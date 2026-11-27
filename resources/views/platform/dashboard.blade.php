@extends('layouts.platform')
@section('title','Ringkasan platform')
@section('content')
<div class="page-heading"><div><div class="eyebrow">PANEL MASTER</div><h1>Ringkasan platform</h1><p>Data agregat seluruh usaha terdaftar. Isi transaksi dan data pelanggan toko tidak ditampilkan di sini.</p></div></div>
<div class="platform-kpis">
    <div class="card"><small>Usaha terdaftar</small><b>{{ number_format($stats['tenants']) }}</b></div>
    <div class="card"><small>Aktif / ditangguhkan</small><b>{{ $stats['active'] }} / {{ $stats['suspended'] }}</b></div>
    <div class="card"><small>Usaha baru 30 hari</small><b>{{ $stats['newTenants'] }}</b></div>
    <div class="card"><small>Pengguna login 30 hari</small><b>{{ number_format($stats['activeUsers']) }}</b></div>
    <div class="card"><small>Transaksi 30 hari (jumlah)</small><b>{{ number_format($stats['sales30d']) }}</b></div>
    <div class="card"><small>Tiket terbuka</small><b><a href="{{ route('platform.tickets.index') }}">{{ $stats['openTickets'] }}</a></b></div>
</div>
<div class="platform-two">
    <div class="card section-card"><div class="table-wrap"><table>
        <thead><tr><th>PAKET</th><th class="text-right">USAHA</th></tr></thead><tbody>
        @foreach($plans as $key => $label)<tr><td>{{ $label }}</td><td class="text-right">{{ $byPlan[$key] ?? 0 }}</td></tr>@endforeach
        </tbody></table></div></div>
    <div class="card section-card"><div class="table-wrap"><table>
        <thead><tr><th>BULAN</th><th class="text-right">USAHA BARU</th></tr></thead><tbody>
        @forelse($growth as $month => $count)<tr><td>{{ $month }}</td><td class="text-right">{{ $count }}</td></tr>@empty<tr><td colspan="2">Belum ada data.</td></tr>@endforelse
        </tbody></table></div></div>
</div>
<div class="card section-card"><div class="table-wrap"><table>
    <thead><tr><th>USAHA TERBARU</th><th>PAKET</th><th>STATUS</th><th>DAFTAR</th></tr></thead><tbody>
    @foreach($latest as $t)<tr><td><a class="td-main" href="{{ route('platform.tenants.show', $t->id) }}">{{ $t->name }}</a></td><td>{{ $plans[$t->plan] ?? $t->plan }}</td>
        <td><span class="status {{ $t->status === 'active' ? '' : 'voided' }}">{{ $t->status === 'active' ? 'Aktif' : 'Ditangguhkan' }}</span></td><td>{{ \Illuminate\Support\Carbon::parse($t->created_at)->format('d M Y') }}</td></tr>@endforeach
    </tbody></table></div></div>
@endsection
