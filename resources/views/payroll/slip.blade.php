<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Slip gaji {{ $item->employee_name }} {{ $run->period_key }}</title>
<style>body{font:14px/1.5 sans-serif;max-width:560px;margin:24px auto;padding:0 16px;color:#111}table{width:100%;border-collapse:collapse}td{padding:4px 0}td:last-child{text-align:right}.tot td{border-top:1px solid #111;font-weight:700}@media print{.tools{display:none}}</style></head>
<body>
@php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp
<div class="tools"><button type="button" onclick="window.print()">Cetak</button></div>
<h2 style="margin-bottom:0">{{ $tenant->legal_name ?: $tenant->name }}</h2>
<p style="margin-top:0">Slip gaji periode {{ $run->period_key }}</p>
<p><b>{{ $item->employee_name }}</b>{{ $item->position ? ' · '.$item->position : '' }}</p>
<table>
    <tr><td>Gaji pokok</td><td>{{ $rp($item->base_salary) }}</td></tr>
    <tr><td>Tunjangan</td><td>{{ $rp($item->allowance) }}</td></tr>
    <tr><td>Bonus</td><td>{{ $rp($item->bonus) }}</td></tr>
    <tr><td>Potongan</td><td>-{{ $rp($item->deduction) }}</td></tr>
    <tr class="tot"><td>Gaji bersih</td><td>{{ $rp($item->net_pay) }}</td></tr>
</table>
@if($item->notes)<p>Catatan: {{ $item->notes }}</p>@endif
</body></html>
