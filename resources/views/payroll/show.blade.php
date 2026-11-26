@extends('layouts.app')
@section('title','Payroll '.$run->period_key)
@section('crumb','Payroll')
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); $label = ['draft' => 'Draft', 'posted' => 'Diposting', 'paid' => 'Dibayar']; $isOwner = ($currentRole->key ?? '') === 'owner'; @endphp
<div class="page-heading"><div><div class="eyebrow">PAYROLL</div><h1>Periode {{ $run->period_key }}</h1><p>{{ $label[$run->status] }} · Total gaji bersih {{ $rp($run->total_net) }}</p></div>
    <a class="btn btn-secondary" href="{{ route('payroll.index') }}">Kembali</a></div>
@if($run->status === 'draft')
<form method="post" action="{{ route('payroll.items.update', $run->id) }}" class="card section-card">@csrf @method('put')
    <div class="table-wrap"><table>
        <thead><tr><th>KARYAWAN</th><th class="text-right">POKOK</th><th class="text-right">TUNJANGAN</th><th>BONUS</th><th>POTONGAN</th><th>CATATAN</th><th class="text-right">BERSIH</th></tr></thead><tbody>
        @foreach($items as $i)
            <tr><td class="td-main">{{ $i->employee_name }}<span class="td-sub">{{ $i->position }}</span></td><td class="money text-right">{{ $rp($i->base_salary) }}</td><td class="money text-right">{{ $rp($i->allowance) }}</td>
                <td><input type="number" min="0" step="1" name="items[{{ $i->id }}][bonus]" value="{{ (int) $i->bonus }}" style="width:110px"></td>
                <td><input type="number" min="0" step="1" name="items[{{ $i->id }}][deduction]" value="{{ (int) $i->deduction }}" style="width:110px"></td>
                <td><input name="items[{{ $i->id }}][notes]" maxlength="255" value="{{ $i->notes }}"></td><td class="money text-right">{{ $rp($i->net_pay) }}</td></tr>
        @endforeach
        </tbody></table></div>
    <div class="form-actions"><button class="btn btn-secondary">Simpan rincian</button></div>
</form>
<div style="display:flex;gap:8px;margin-top:12px">
    @if($isOwner)<form method="post" action="{{ route('payroll.post', $run->id) }}" onsubmit="return confirm('Posting payroll ke jurnal? Rincian tidak bisa diubah lagi.')">@csrf<button class="btn btn-primary">Posting ke jurnal</button></form>@endif
    <form method="post" action="{{ route('payroll.destroy', $run->id) }}" onsubmit="return confirm('Hapus draft ini?')">@csrf @method('delete')<button class="btn btn-secondary">Hapus draft</button></form>
</div>
@else
<div class="card section-card"><div class="table-wrap"><table>
    <thead><tr><th>KARYAWAN</th><th class="text-right">POKOK</th><th class="text-right">TUNJANGAN</th><th class="text-right">BONUS</th><th class="text-right">POTONGAN</th><th class="text-right">BERSIH</th><th></th></tr></thead><tbody>
    @foreach($items as $i)
        <tr><td class="td-main">{{ $i->employee_name }}</td><td class="money text-right">{{ $rp($i->base_salary) }}</td><td class="money text-right">{{ $rp($i->allowance) }}</td><td class="money text-right">{{ $rp($i->bonus) }}</td>
            <td class="money text-right">{{ $rp($i->deduction) }}</td><td class="money text-right">{{ $rp($i->net_pay) }}</td><td><a href="{{ route('payroll.slip', [$run->id, $i->id]) }}" target="_blank">Slip</a></td></tr>
    @endforeach
    </tbody></table></div></div>
@if($run->status === 'posted' && $isOwner)
<form method="post" action="{{ route('payroll.pay', $run->id) }}" class="card section-card" style="display:flex;gap:12px;align-items:end;flex-wrap:wrap">@csrf
    <label style="max-width:220px">Dibayar dari<select name="method"><option value="cash">Kas tunai</option><option value="bank_transfer">Rekening bank</option></select></label>
    <button class="btn btn-primary">Catat pembayaran</button>
</form>
@elseif($run->status === 'paid')<p class="muted">Dibayar {{ $run->payment_method === 'cash' ? 'tunai' : 'via bank' }} pada {{ \Illuminate\Support\Carbon::parse($run->paid_at)->format('d M Y') }}.</p>@endif
@endif
@endsection
