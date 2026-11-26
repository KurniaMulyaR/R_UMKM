@extends('layouts.app')
@section('title','Hak akses')
@section('crumb','Hak akses')
@section('content')
<div class="page-heading"><div><div class="eyebrow">AKSES PENGGUNA</div><h1>Hak akses per peran</h1><p>Dibaca langsung dari aturan akses aplikasi. "Penuh" berarti semua halaman dan aksi modul itu terbuka; angka berarti sebagian saja (misal hanya melihat).</p></div><a class="btn btn-secondary" href="{{ route('staff.index') }}">← Tim usaha</a></div>
<div class="card section-card"><table class="table"><thead><tr><th>Modul</th>@foreach($roles as $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>
@foreach($modules as $label => $module)
    <tr><td>{{ $label }}</td>@foreach($roles as $key => $roleLabel)
        @php $count = $module['roles'][$key] ?? 0; @endphp
        <td>@if($count === 0)—@elseif($count === $module['total'])Penuh@else{{ $count }}/{{ $module['total'] }}@endif</td>
    @endforeach</tr>
@endforeach
</tbody></table>
<p class="muted" style="margin-top:12px">Peran bersifat tetap. Pengaturan izin per fitur yang bisa diubah sendiri belum tersedia.</p></div>
@endsection
