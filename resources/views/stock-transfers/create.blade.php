@extends('layouts.app')
@section('title','Transfer baru')
@section('crumb','Transfer baru')
@section('content')
<div class="page-heading"><div><div class="eyebrow">PERSEDIAAN</div><h1>Transfer baru</h1><p>Isi jumlah hanya untuk barang yang dikirim. Kolom kosong dilewati.</p></div></div>
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('stock-transfers.store') }}" class="card section-card">@csrf
    <label>Outlet tujuan
        <select name="to_outlet_id" required>
            <option value="">Pilih outlet</option>
            @foreach($destinations as $destination)<option value="{{ $destination->id }}" @selected(old('to_outlet_id') === $destination->id)>{{ $destination->name }}</option>@endforeach
        </select>
    </label>
    @if($destinations->isEmpty())<p class="muted">Belum ada outlet lain. Tambahkan di menu Outlet.</p>@endif
    <table class="table" style="margin-top:14px"><thead><tr><th>Produk</th><th>SKU</th><th>Stok tersedia</th><th>Jumlah kirim</th></tr></thead><tbody>
    @forelse($rows as $row)
        <tr><td>{{ $row->name }}</td><td>{{ $row->sku }}</td><td>{{ rtrim(rtrim(number_format((float) $row->quantity_on_hand, 4, ',', '.'), '0'), ',') }} {{ $row->unit_symbol }}</td>
            <td><input type="number" name="items[{{ $row->variant_id }}]" min="0" max="{{ $row->quantity_on_hand }}" step="any" value="{{ old('items.'.$row->variant_id) }}" style="max-width:140px"></td></tr>
    @empty
        <tr><td colspan="4" class="muted">Tidak ada stok di outlet ini.</td></tr>
    @endforelse
    </tbody></table>
    <label style="margin-top:14px">Catatan <span class="optional">opsional</span><input name="notes" maxlength="500" value="{{ old('notes') }}"></label>
    <div class="form-actions"><button class="btn btn-primary">Kirim transfer</button> <a class="btn btn-secondary" href="{{ route('stock-transfers.index') }}">Batal</a></div>
</form>
@endsection
