@extends('layouts.app', ['title' => 'Segurança'])

@section('content')
<div class="page-head">
    <div><h1>Central de segurança</h1><p>Últimos 30 dias: tentativas de acesso, contas bloqueadas, arquivos barrados, exportações e pontos de atenção da equipe.</p></div>
    <a class="btn" href="{{ route('audit.index') }}">Trilha de auditoria</a>
</div>

<div class="grid grid-4 mb-2">
    @foreach ($kpi as $label => $value)
        <div class="card kpi"><div class="kpi__label">{{ $label }}</div><div class="kpi__value">{{ $value }}</div><div class="kpi__hint">30 dias</div></div>
    @endforeach
</div>

<div class="grid grid-2 mb-2">
    <section class="card">
        <div class="card__head"><h2>Pontos de atenção</h2></div>
        <div class="card__body stack small">
            @if ($no2fa->isNotEmpty())
                <div class="alert alert-warning mb-0"><strong>{{ $no2fa->count() }} usuário(s) com poderes administrativos sem verificação em duas etapas (2FA):</strong> {{ $no2fa->pluck('name')->implode(', ') }}. Peça para ativarem em "Meu perfil".</div>
            @else
                <div class="alert alert-success mb-0">Todos os administradores usam 2FA.</div>
            @endif
            @if ($locked->isNotEmpty())<div><strong>Contas bloqueadas agora:</strong> {{ $locked->pluck('name')->implode(', ') }}</div>@endif
            <div><strong>Tokens de API ativos:</strong> {{ $tokens }}</div>
            <div><strong>Varredura de arquivos:</strong> {{ $scanner === 'clamav' ? 'antivírus ClamAV + verificações próprias' : 'verificações próprias (PDF com script, imagem com código, EICAR) — sem antivírus no servidor' }}</div>
        </div>
    </section>
    <section class="card">
        <div class="card__head"><h2>Retenção de dados (LGPD)</h2></div>
        <form method="post" action="{{ route('security.retention') }}" class="card__body form-grid">@csrf @method('put')
            <p class="help col-12">Prazos (em dias) para apagar ou reduzir dados <strong>operacionais</strong>. Prontuário, documentos médicos, anexos, financeiro e auditoria <strong>nunca</strong> entram nesta rotina (guarda legal).</p>
            @foreach ($labels as $key => $label)
                <x-field :name="$key" :label="$label" type="number" min="0" max="3650" col="col-6" :value="$policy[$key]" required />
            @endforeach
            <div class="col-12 form-actions"><button class="btn" type="submit">Salvar política</button></div>
        </form>
    </section>
</div>

<section class="card">
    <div class="card__head"><h2>Eventos de segurança recentes</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Quando</th><th>Evento</th><th>Usuário</th><th>IP</th><th>Detalhe</th></tr></thead>
        <tbody>
        @forelse ($events as $e)
            @php $m = json_decode($e->metadata ?? '{}', true) ?: []; @endphp
            <tr><td class="nowrap small">{{ \Carbon\CarbonImmutable::parse($e->created_at)->timezone('America/Sao_Paulo')->format('d/m H:i') }}</td>
                <td class="mono small">{{ $e->action }} @if ($e->result !== 'success')<span class="badge badge-warning">{{ $e->result }}</span>@endif</td>
                <td class="small">{{ $names[$e->user_id] ?? '—' }}</td><td class="mono small">{{ $e->ip_address }}</td>
                <td class="small">{{ \Illuminate\Support\Str::limit(collect($m)->except(['policy'])->map(fn ($v, $k) => $k.': '.(is_scalar($v) ? $v : json_encode($v)))->implode(' · '), 120) }}</td></tr>
        @empty
            <tr><td colspan="5" class="empty">Nenhum evento no período.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endsection
