@extends('layouts.app')
@section('title','Jurnal '.$entry->entry_number)
@section('crumb','Jurnal')
@section('content')
@php $rp = fn ($n) => (float) $n > 0 ? 'Rp '.number_format($n, 0, ',', '.') : ''; @endphp
<div class="page-heading"><div><div class="eyebrow">JURNAL</div><h1>{{ $entry->entry_number }}</h1><p>{{ \Illuminate\Support\Carbon::parse($entry->entry_date)->format('d/m/Y') }} · {{ $entry->description }} · {{ $entry->status }}</p></div></div>
@if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
<div class="card section-card"><table class="table"><thead><tr><th>Akun</th><th>Memo</th><th>Debit</th><th>Kredit</th></tr></thead><tbody>
    @foreach($lines as $line)<tr><td>{{ $line->code }} · {{ $line->account_name }}</td><td>{{ $line->description }}</td><td>{{ $rp($line->debit) }}</td><td>{{ $rp($line->credit) }}</td></tr>@endforeach
    <tr><td colspan="2"><b>Total</b></td><td><b>{{ $rp($lines->sum('debit')) }}</b></td><td><b>{{ $rp($lines->sum('credit')) }}</b></td></tr>
</tbody></table></div>
@endsection
