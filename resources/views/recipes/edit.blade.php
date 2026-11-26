@extends('layouts.app')
@section('title','Resep '.$menu->product_name)
@section('crumb','Menu & resep')
@section('content')
@php
    $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.');
    $qty = fn ($n) => rtrim(rtrim(number_format((float) $n, 4, '.', ''), '0'), '.');
    $rows = old('lines') ? collect(old('lines'))->values() : $lines->map(fn ($l) => ['ingredient_variant_id' => $l->ingredient_variant_id, 'quantity' => $qty($l->quantity), 'waste_percent' => $qty($l->waste_percent)])->values();
    if ($rows->isEmpty()) { $rows = collect([['ingredient_variant_id' => '', 'quantity' => '', 'waste_percent' => 0]]); }
@endphp
<div class="page-heading"><div><div class="eyebrow">RESEP</div><h1>{{ $menu->product_name }}{{ $menu->variant_name !== 'Default' ? ' - '.$menu->variant_name : '' }}</h1>
    <p>@if($cost !== null)Biaya bahan {{ $rp($cost) }} per porsi · harga jual {{ $rp($price) }}@if($price > 0) · margin {{ number_format(($price - $cost) / $price * 100, 1, ',', '.') }}%@endif @else Belum ada resep.@endif</p></div>
    <a class="btn btn-secondary" href="{{ route('recipes.index') }}">Kembali</a></div>
@error('lines')<div class="alert alert-error">{{ $message }}</div>@enderror
<form method="post" action="{{ route('recipes.update', $menu->id) }}" class="card form-card">@csrf @method('put')
    <div class="form-grid">
        <label>Resep ini menghasilkan (porsi)<input type="number" name="yield_quantity" required min="0.0001" step="any" value="{{ old('yield_quantity', $recipe ? $qty($recipe->yield_quantity) : 1) }}"></label>
        <label>Catatan <span class="optional">opsional</span><input name="notes" maxlength="2000" value="{{ old('notes', $recipe->notes ?? '') }}"></label>
    </div>
    <h2 style="margin-top:16px">Bahan</h2>
    <div id="recipe-lines">
    @foreach($rows as $i => $row)
        <div class="form-grid recipe-line" style="grid-template-columns:2fr 1fr 1fr auto;align-items:end">
            <label>Bahan<select name="lines[{{ $i }}][ingredient_variant_id]" required><option value="">Pilih bahan</option>
                @foreach($ingredients as $ing)<option value="{{ $ing->id }}" @selected(($row['ingredient_variant_id'] ?? '') === $ing->id)>{{ $ing->product_name }}{{ $ing->variant_name !== 'Default' ? ' - '.$ing->variant_name : '' }} ({{ $ing->symbol }})</option>@endforeach</select></label>
            <label>Jumlah per resep<input type="number" name="lines[{{ $i }}][quantity]" required min="0.0001" step="any" value="{{ $row['quantity'] ?? '' }}"></label>
            <label>Susut % <span class="optional">opsional</span><input type="number" name="lines[{{ $i }}][waste_percent]" min="0" max="90" step="any" value="{{ $row['waste_percent'] ?? 0 }}"></label>
            <button type="button" class="btn btn-secondary" onclick="this.closest('.recipe-line').remove()">Hapus</button>
        </div>
    @endforeach
    </div>
    <div class="form-actions"><button type="button" class="btn btn-secondary" id="add-line">＋ Tambah bahan</button><button class="btn btn-primary">Simpan resep</button></div>
</form>
@if($recipe)
<div class="card section-card"><h2>Rincian biaya</h2><div class="table-wrap"><table>
    <thead><tr><th>BAHAN</th><th class="text-right">JUMLAH</th><th class="text-right">HARGA/SATUAN</th><th class="text-right">STOK</th><th class="text-right">BIAYA/PORSI</th></tr></thead><tbody>
    @foreach($lines as $l)
        <tr><td>{{ $l->product_name }}</td><td class="text-right">{{ $qty($l->quantity) }} {{ $l->symbol }}{{ (float) $l->waste_percent > 0 ? ' (+'.$qty($l->waste_percent).'%)' : '' }}</td><td class="money text-right">{{ $rp($l->unit_cost) }}</td><td class="text-right">{{ $qty($l->quantity_on_hand ?? 0) }}</td><td class="money text-right">{{ $rp($l->line_cost) }}</td></tr>
    @endforeach
    </tbody></table></div>
    <form method="post" action="{{ route('recipes.destroy', $menu->id) }}" onsubmit="return confirm('Hapus resep ini?')" style="margin-top:12px">@csrf @method('delete')<button class="btn btn-secondary">Hapus resep</button></form></div>
@endif
<script>
document.getElementById('add-line').addEventListener('click', function () {
    var box = document.getElementById('recipe-lines');
    var line = box.querySelector('.recipe-line').cloneNode(true);
    var index = Date.now();
    line.querySelectorAll('select,input').forEach(function (el) {
        el.name = el.name.replace(/lines\[[^\]]+\]/, 'lines[' + index + ']');
        el.value = el.name.indexOf('waste') > -1 ? 0 : '';
    });
    box.appendChild(line);
});
</script>
@endsection
