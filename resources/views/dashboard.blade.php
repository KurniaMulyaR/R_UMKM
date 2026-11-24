@extends('layouts.app')
@section('title','Ringkasan')
@section('crumb','Ringkasan')
@section('content')
<div class="dashboard-page">
    <div class="page-heading dashboard-heading">
        <div>
            <div class="eyebrow">{{ strtoupper(now($currentTenant->timezone)->locale('id')->translatedFormat('l, d F Y')) }}</div>
            <h1>Halo, {{ explode(' ', auth()->user()->name)[0] }} 👋</h1>
            <p>Ringkasan usaha kamu hari ini.</p>
        </div>
        <a class="btn btn-primary dashboard-action" href="{{ route('pos.index') }}"><span>＋</span> Transaksi baru</a>
    </div>

    @if(!$openShift)
        <div class="shift-banner dashboard-shift-banner">
            <span class="shift-banner-copy"><b>Shift kasir belum dibuka</b><small>Buka shift untuk mulai mencatat penjualan.</small></span>
            <a class="btn btn-soft" href="{{ route('shifts.index') }}">Buka shift <span>→</span></a>
        </div>
    @endif

    <div class="dashboard-kpis">
        <article class="card dashboard-kpi">
            <div class="dashboard-kpi-top"><span class="dashboard-kpi-icon blue">↗</span><span class="dashboard-kpi-caption">HARI INI</span></div>
            <span class="dashboard-kpi-label">Omzet penjualan</span>
            <b class="dashboard-kpi-value">Rp {{ number_format($summary['revenue'],0,',','.') }}</b>
            <span class="dashboard-kpi-foot">Total transaksi selesai</span>
        </article>
        <article class="card dashboard-kpi">
            <div class="dashboard-kpi-top"><span class="dashboard-kpi-icon cyan">▦</span><span class="dashboard-kpi-caption">HARI INI</span></div>
            <span class="dashboard-kpi-label">Transaksi selesai</span>
            <b class="dashboard-kpi-value">{{ number_format($summary['transactions'],0,',','.') }}</b>
            <span class="dashboard-kpi-foot">Lunas dan kasbon</span>
        </article>
        <article class="card dashboard-kpi">
            <div class="dashboard-kpi-top"><span class="dashboard-kpi-icon violet">✦</span><span class="dashboard-kpi-caption">PERKIRAAN</span></div>
            <span class="dashboard-kpi-label">Untung kotor</span>
            <b class="dashboard-kpi-value">Rp {{ number_format($summary['gross_profit'],0,',','.') }}</b>
            <span class="dashboard-kpi-foot">Omzet dikurangi HPP</span>
        </article>
        <article class="card dashboard-kpi">
            <div class="dashboard-kpi-top"><span class="dashboard-kpi-icon orange">□</span><span class="dashboard-kpi-caption">KATALOG</span></div>
            <span class="dashboard-kpi-label">Produk aktif</span>
            <b class="dashboard-kpi-value">{{ number_format($productCount,0,',','.') }}</b>
            <span class="dashboard-kpi-foot">Produk di ruang usaha ini</span>
        </article>
    </div>

    <div class="dashboard-overview-grid">
        <section class="card dashboard-panel activity-panel">
            <div class="dashboard-panel-heading">
                <div><span class="dashboard-panel-eyebrow">PERFORMA USAHA</span><h2>Aktivitas penjualan</h2><p>Omzet harian selama 7 hari terakhir</p></div>
                <div class="weekly-total"><small>Total 7 hari</small><b>Rp {{ number_format($weeklyActivity->sum('revenue'),0,',','.') }}</b></div>
            </div>
            <div class="activity-chart" role="img" aria-label="Grafik omzet penjualan tujuh hari terakhir">
                <div class="chart-axis"><span>Rp {{ number_format($maxDailyRevenue,0,',','.') }}</span><span>Rp {{ number_format($maxDailyRevenue / 2,0,',','.') }}</span><span>Rp 0</span></div>
                <div class="chart-plot">
                    <div class="chart-gridlines"><i></i><i></i><i></i></div>
                    <div class="chart-bars">
                        @foreach($weeklyActivity as $activity)
                            @php $barHeight = $activity['revenue'] > 0 ? max(5, ($activity['revenue'] / $maxDailyRevenue) * 100) : 2; @endphp
                            <div class="chart-day" title="{{ $activity['label'] }} {{ \Illuminate\Support\Carbon::parse($activity['date'])->format('d/m') }} · Rp {{ number_format($activity['revenue'],0,',','.') }} · {{ $activity['transactions'] }} transaksi">
                                <div class="chart-bar-track"><span class="chart-bar" style="height: {{ $barHeight }}%"></span></div>
                                <span class="chart-day-label">{{ $activity['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="chart-legend"><span><i></i> Omzet penjualan</span><small>Data hanya transaksi selesai</small></div>
        </section>

        <section class="card dashboard-panel stock-panel">
            <div class="dashboard-panel-heading compact">
                <div><span class="dashboard-panel-eyebrow">INVENTARIS</span><h2>Perlu diperiksa</h2><p>Produk yang mencapai batas minimum</p></div>
                <a class="panel-link" href="{{ route('products.index') }}" aria-label="Kelola produk">↗</a>
            </div>
            @if($lowStock->isEmpty())
                <div class="dashboard-empty-stock"><span>✓</span><b>Stok aman</b><small>Belum ada produk yang menipis.</small></div>
            @else
                <div class="dashboard-stock-list">
                    @foreach($lowStock as $item)
                        <div class="dashboard-stock-item">
                            <span class="stock-product-mark">{{ mb_strtoupper(mb_substr($item->name,0,1)) }}</span>
                            <span class="stock-product-copy"><b>{{ $item->name }}</b><small>{{ $item->sku ?: 'Tanpa SKU' }}</small></span>
                            <span class="stock-quantity">{{ number_format((float)$item->quantity_on_hand,0,',','.') }}<small>tersisa</small></span>
                        </div>
                    @endforeach
                </div>
                <a class="stock-footer-link" href="{{ route('products.index') }}">Lihat persediaan <span>→</span></a>
            @endif
        </section>
    </div>

    <section class="card dashboard-panel recent-panel">
        <div class="dashboard-panel-heading compact">
            <div><span class="dashboard-panel-eyebrow">AKTIVITAS TERBARU</span><h2>Transaksi terbaru</h2><p>Penjualan terakhir di outlet ini</p></div>
            <a class="panel-link text-link" href="{{ route('sales.index') }}">Lihat semua <span>→</span></a>
        </div>
        @if($recentSales->isEmpty())
            <div class="empty-state dashboard-sales-empty"><div class="empty-icon">▤</div><b>Belum ada penjualan</b><span>Transaksi pertamamu akan muncul di sini.</span><a class="btn btn-secondary" href="{{ route('pos.index') }}">Buka kasir</a></div>
        @else
            <div class="table-wrap dashboard-table-wrap">
                <table class="dashboard-table">
                    <thead><tr><th>TRANSAKSI</th><th>PELANGGAN</th><th>KASIR</th><th>WAKTU</th><th class="text-right">TOTAL</th></tr></thead>
                    <tbody>
                        @foreach($recentSales as $sale)
                            <tr>
                                <td><a class="td-main" href="{{ route('sales.show',$sale->id) }}">{{ $sale->sale_number }}</a></td>
                                <td>{{ $sale->customer_name ?: 'Pelanggan umum' }}</td>
                                <td>{{ $sale->cashier_user_id === auth()->id() ? 'Kamu' : 'Tim kasir' }}</td>
                                <td>{{ \Illuminate\Support\Carbon::parse($sale->sold_at)->format('d M, H:i') }}</td>
                                <td class="money text-right">Rp {{ number_format($sale->grand_total,0,',','.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
