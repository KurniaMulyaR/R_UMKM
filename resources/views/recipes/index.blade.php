@extends('layouts.app')
@section('title','Menu & resep')
@section('crumb','Menu & resep')
@section('content')
@php $rp = fn ($n) => 'Rp '.number_format($n, 0, ',', '.'); @endphp
<div class="page-heading"><div><div class="eyebrow">DATA USAHA</div><h1>Menu & resep</h1><p>Resep bahan per porsi, biaya bahan, dan margin. Bahan terpotong otomatis saat menu terjual.</p></div></div>
@error('recipe')<div class="alert alert-error">{{ $message }}</div>@enderror
<div class="card section-card"><div class="table-wrap"><table>
    <thead><tr><th>MENU</th><th class="text-right">HARGA</th><th class="text-right">BIAYA BAHAN</th><th class="text-right">MARGIN</th><th></th></tr></thead><tbody>
    @forelse($menus as $m)
        <tr><td class="td-main">{{ $m->product_name }}@if($m->variant_name !== 'Default') - {{ $m->variant_name }}@endif<span class="td-sub">{{ $m->recipe_id ? 'Resep aktif · hasil '.rtrim(rtrim(number_format((float) $m->yield_quantity, 4, ',', '.'), '0'), ',').' porsi' : 'Belum ada resep' }}</span></td>
            <td class="money text-right">{{ $rp($m->price) }}</td><td class="money text-right">{{ $m->cost === null ? '-' : $rp($m->cost) }}</td>
            <td class="text-right">{{ $m->margin === null ? '-' : number_format($m->margin, 1, ',', '.').'%' }}</td>
            <td><a class="btn btn-secondary" href="{{ route('recipes.edit', $m->id) }}">{{ $m->recipe_id ? 'Ubah resep' : 'Buat resep' }}</a></td></tr>
    @empty
        <tr><td colspan="5"><div class="empty-state"><div class="empty-icon">▤</div><b>Belum ada menu</b><span>Buat produk dengan "lacak stok" dimatikan (menu buat-sendiri), lalu beri resep di sini.</span></div></td></tr>
    @endforelse
    </tbody></table></div></div>
@endsection
