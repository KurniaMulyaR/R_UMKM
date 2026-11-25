@extends('layouts.app')
@section('title','Jurnal manual')
@section('crumb','Jurnal manual')
@section('content')
<div class="page-heading"><div><div class="eyebrow">PEMBUKUAN</div><h1>Jurnal manual</h1><p>Total debit harus sama dengan total kredit. Akun persediaan, piutang, dan hutang supplier hanya bergerak lewat transaksinya.</p></div></div>
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('journals.store') }}" class="card section-card">@csrf
    <div class="form-grid">
        <label>Tanggal<input type="date" name="entry_date" required max="{{ now()->toDateString() }}" value="{{ old('entry_date', now()->toDateString()) }}"></label>
        <label>Keterangan<input name="description" required maxlength="300" value="{{ old('description') }}"></label>
    </div>
    <table class="table" id="journal-lines"><thead><tr><th>Akun</th><th>Debit</th><th>Kredit</th><th>Memo</th></tr></thead><tbody>
    @for($i = 0; $i < max(4, count(old('lines', []))); $i++)
        <tr><td><select name="lines[{{ $i }}][account_id]"><option value="">—</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected(old("lines.$i.account_id") === $account->id)>{{ $account->code }} · {{ $account->name }}</option>@endforeach</select></td>
            <td><input type="number" step="1" min="0" name="lines[{{ $i }}][debit]" value="{{ old("lines.$i.debit") }}"></td>
            <td><input type="number" step="1" min="0" name="lines[{{ $i }}][credit]" value="{{ old("lines.$i.credit") }}"></td>
            <td><input name="lines[{{ $i }}][memo]" maxlength="200" value="{{ old("lines.$i.memo") }}"></td></tr>
    @endfor
    </tbody></table>
    <button type="button" class="btn btn-secondary" id="add-line">Tambah baris</button>
    <div class="form-actions"><button class="btn btn-primary">Posting jurnal</button> <a class="btn btn-secondary" href="{{ route('journals.index') }}">Batal</a></div>
</form>
@push('scripts')
<script>
document.getElementById('add-line').addEventListener('click', function () {
    var body = document.querySelector('#journal-lines tbody'), index = body.rows.length;
    var row = body.rows[0].cloneNode(true);
    row.querySelectorAll('select, input').forEach(function (field) { field.name = field.name.replace(/lines\[\d+\]/, 'lines[' + index + ']'); field.value = ''; });
    body.appendChild(row);
});
</script>
@endpush
@endsection
