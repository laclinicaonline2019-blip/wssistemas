@extends('layouts.app', ['title' => 'Conciliação bancária'])

@section('content')
<div class="page-head">
    <div><h1>Conciliação bancária</h1><p>Importe o extrato (OFX ou CSV do internet banking) ou conecte por Open Finance e confira cada lançamento do banco com o financeiro: o que bate, o que falta lançar (tarifas, rendimentos) e o que não é da clínica.</p></div>
</div>
<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Contas bancárias</h2></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Conta</th><th>Extrato</th><th>A conciliar</th><th></th></tr></thead>
            <tbody>
            @forelse ($accounts as $a)
                <tr><td><strong>{{ $a->name }}</strong><div class="small muted">{{ $a->bank_code ? 'Banco '.$a->bank_code.' · ' : '' }}{{ $a->agency ? 'ag. '.$a->agency : '' }} {{ $a->account_number ? 'c/c '.$a->account_number : '' }} {{ $a->branch ? '· '.$a->branch->name : '' }}</div>
                        @unless ($a->is_active)<span class="badge">desativada</span>@endunless</td>
                    <td class="small">{{ $a->sync_provider === 'pluggy' ? 'Open Finance' : 'OFX/CSV' }}@if ($a->sync_error)<div class="text-danger">{{ \Illuminate\Support\Str::limit($a->sync_error, 60) }}</div>@endif</td>
                    <td>@if ($a->pending_count)<span class="badge badge-warning">{{ $a->pending_count }}</span>@else<span class="badge badge-success">em dia</span>@endif</td>
                    <td class="actions"><a class="btn btn-sm" href="{{ route('bank.accounts.show', $a) }}">Abrir</a></td></tr>
            @empty
                <tr><td colspan="4" class="empty">Cadastre a primeira conta bancária.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>
    <section class="card">
        <div class="card__head"><h2>Nova conta</h2></div>
        <form method="post" action="{{ route('bank.accounts.store') }}" class="card__body form-grid">@csrf
            @include('banking._account-fields', ['a' => null])
            <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Cadastrar conta</button></div>
        </form>
    </section>
</div>
@endsection
