@extends('layouts.app', ['title' => 'Prontidão para produção'])

@section('content')
<div class="page-head">
    <div><h1>Prontidão para homologação e produção</h1><p>Mesma verificação do comando <span class="mono">php artisan aivexa:preflight</span>: configuração, cron, fila, integrações, segurança e backup.</p></div>
    <div class="row"><span class="badge badge-success">{{ $summary['ok'] }} ok</span><span class="badge badge-warning">{{ $summary['warn'] }} aviso(s)</span><span class="badge badge-danger">{{ $summary['error'] }} erro(s)</span></div>
</div>
@if ($summary['error'])<div class="alert alert-error">Há itens com <strong>erro</strong>: não coloque em produção antes de corrigir.</div>@endif
@foreach ($items as $area => $list)
    <section class="card mb-2">
        <div class="card__head"><h2>{{ $area }}</h2></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Item</th><th>Situação</th><th>Detalhe</th><th>Como corrigir</th></tr></thead>
            <tbody>
            @foreach ($list as $i)
                <tr><td><strong>{{ $i['item'] }}</strong></td>
                    <td><span class="badge {{ ['ok' => 'badge-success', 'warn' => 'badge-warning', 'error' => 'badge-danger'][$i['level']] }}">{{ ['ok' => 'OK', 'warn' => 'Aviso', 'error' => 'Erro'][$i['level']] }}</span></td>
                    <td class="small">{{ $i['detail'] }}</td><td class="small muted">{{ $i['level'] === 'ok' ? '' : $i['fix'] }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
@endforeach
@endsection
