<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title')</title>
<style>
    :root{color-scheme:light}
    *{box-sizing:border-box}
    body{margin:0;background:#eef1f6;color:#111827;font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
    .toolbar{position:sticky;top:0;display:flex;gap:10px;justify-content:center;padding:12px;background:#fff;border-bottom:1px solid #dbe1ec}
    .toolbar button,.toolbar a{font:600 13px system-ui;padding:8px 16px;border-radius:10px;border:1px solid #c7d0df;background:#fff;color:#111827;text-decoration:none;cursor:pointer}
    .toolbar button{background:#2f5bea;border-color:#2f5bea;color:#fff}
    .sheet{width:210mm;min-height:297mm;margin:18px auto;background:#fff;padding:16mm 15mm;box-shadow:0 6px 24px rgba(15,23,42,.12)}
    .head{display:flex;justify-content:space-between;gap:20px;border-bottom:2px solid #111827;padding-bottom:12px;margin-bottom:16px}
    .head h1{margin:0 0 4px;font-size:20px}.head small{display:block;color:#4b5563}
    .doc-title{text-align:right}.doc-title h2{margin:0;font-size:22px;letter-spacing:.06em}
    .meta{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
    .meta h3{margin:0 0 4px;font-size:11px;letter-spacing:.08em;color:#6b7280;text-transform:uppercase}
    table{width:100%;border-collapse:collapse;margin-bottom:12px}
    th{background:#f3f4f6;text-align:left;font-size:12px;padding:8px;border:1px solid #d1d5db}
    td{padding:8px;border:1px solid #e5e7eb;vertical-align:top}
    .num{text-align:right;white-space:nowrap}
    .totals{margin-left:auto;width:55%}.totals td{border:0;border-bottom:1px solid #eee;padding:5px 8px}
    .totals .grand td{font-weight:700;font-size:15px;border-top:2px solid #111827}
    .stamp{display:inline-block;border:2px solid;padding:3px 12px;font-weight:800;letter-spacing:.1em;transform:rotate(-4deg);margin-top:8px}
    .stamp.paid{color:#15803d}.stamp.unpaid{color:#b91c1c}
    .sign{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-top:40px;text-align:center}
    .sign div{padding-top:70px;border-top:0}.sign span{display:block;border-top:1px solid #111827;padding-top:4px;font-size:12px}
    .note{color:#4b5563;font-size:12px;margin-top:14px}
    @media print{body{background:#fff}.toolbar{display:none}.sheet{margin:0;box-shadow:none;width:auto;min-height:0;padding:0}@page{size:A4;margin:14mm}}
</style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()">Cetak / simpan PDF</button><a href="{{ route('sales.show', $sale->id) }}">← Kembali</a></div>
<main class="sheet">
    <header class="head">
        <div><h1>{{ $tenant->legal_name ?: $tenant->name }}</h1>
            @if($outlet->address)<small>{{ $outlet->address }}</small>@endif
            @if($outlet->phone ?: $tenant->phone)<small>Telp {{ $outlet->phone ?: $tenant->phone }}@if($tenant->email) · {{ $tenant->email }}@endif</small>@endif</div>
        <div class="doc-title"><h2>@yield('doc_name')</h2><small>No. {{ $number ?? $payment->payment_number }}</small></div>
    </header>
    @yield('body')
    @php $footerNote = json_decode((string) $tenant->settings, true)['document_footer'] ?? null; @endphp
    @if($footerNote)<p style="margin-top:18px;font-size:12px;text-align:center">{{ $footerNote }}</p>@endif
</main>
</body>
</html>
