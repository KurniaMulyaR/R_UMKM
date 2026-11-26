@extends('layouts.app')
@section('title','Opname baru')
@section('crumb','Opname baru')
@section('content')
<div class="page-heading"><div><div class="eyebrow">PERSEDIAAN</div><h1>Opname baru</h1><p>Isi jumlah fisik yang benar. Lebih besar dari stok sistem akan menambah stok, lebih kecil akan menguranginya. Kolom kosong dilewati.</p></div></div>
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('stock-counts.store') }}" class="card section-card">@csrf
    <table class="table"><thead><tr><th>Produk</th><th>SKU</th><th>Stok sistem</th><th>Jumlah fisik</th><th>Perkiraan selisih</th></tr></thead><tbody>
    @forelse($rows as $row)
        <tr><td>{{ $row->name }}</td><td>{{ $row->sku }}</td><td>{{ rtrim(rtrim(number_format((float) $row->system_quantity, 4, ',', '.'), '0'), ',') }} {{ $row->unit_symbol }}</td>
            <td><input type="number" name="counted[{{ $row->variant_id }}]" min="0" step="any" value="{{ old('counted.'.$row->variant_id) }}" data-counted-quantity data-system-quantity="{{ $row->system_quantity }}" data-unit-symbol="{{ $row->unit_symbol }}" style="max-width:140px"></td>
            <td><b data-count-variance class="muted">—</b></td></tr>
    @empty
        <tr><td colspan="5" class="muted">Belum ada produk yang melacak stok.</td></tr>
    @endforelse
    </tbody></table>
    <label style="margin-top:14px">Catatan <span class="optional">opsional</span><input name="notes" maxlength="1000" value="{{ old('notes') }}"></label>
    <div class="form-actions"><button class="btn btn-primary">Kirim opname</button> <a class="btn btn-secondary" href="{{ route('stock-counts.index') }}">Batal</a></div>
</form>
@endsection
@push('scripts')<script>
const formatCountQuantity = (value) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 4 }).format(value);
document.querySelectorAll('[data-counted-quantity]').forEach((input) => {
    const preview = input.closest('tr').querySelector('[data-count-variance]');
    const updateVariance = () => {
        if (input.value === '') { preview.textContent = '—'; preview.className = 'muted'; return; }
        const delta = Number(input.value) - Number(input.dataset.systemQuantity);
        const sign = delta > 0 ? '+' : '';
        preview.textContent = `${sign}${formatCountQuantity(delta)} ${input.dataset.unitSymbol}`;
        preview.className = delta > 0 ? 'movement-quantity positive' : (delta < 0 ? 'movement-quantity negative' : 'muted');
    };
    input.addEventListener('input', updateVariance);
    updateVariance();
});
</script>@endpush
