@extends('layouts.app', ['title' => 'Repasses médicos'])

@php
    use App\Core\Support\Format;
    $tz = 'America/Sao_Paulo';
    $byDoctor = $doctors->keyBy('id');
@endphp

@section('content')
@include('finance._tabs')
<div class="page-head">
    <div><h1>Repasses médicos (split)</h1><p>Parte do médico em cada recebimento. Cobranças ASAAS com a carteira do médico já caem separadas (split nativo);
        os demais recebimentos (dinheiro, maquininha, Cielo) geram repasse interno, pago no fechamento.</p></div>
    <form method="get" class="row">
        <label class="sr-only" for="sf">De</label><input id="sf" type="date" name="from" value="{{ $from }}" class="input w-auto">
        <label class="sr-only" for="st">Até</label><input id="st" type="date" name="to" value="{{ $to }}" class="input w-auto">
        <button class="btn" type="submit">Filtrar</button>
    </form>
</div>

<section class="card mb-2">
    <div class="card__head"><h2>Resumo do período</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Médico</th><th class="t-right">Split nativo (já recebido pelo médico)</th><th class="t-right">Repasse interno a pagar</th><th class="t-right">Já repassado</th><th></th></tr></thead>
        <tbody>
        @forelse ($summary as $doctorId => $rows)
            @php
                $native = (int) $rows->where('mode', 'native')->where('status', 'settled')->sum('total');
                $pending = (int) $rows->where('mode', 'internal')->where('status', 'pending')->sum('total');
                $settled = (int) $rows->where('mode', 'internal')->where('status', 'settled')->sum('total');
                $doc = $byDoctor[$doctorId] ?? null;
            @endphp
            <tr>
                <td>{{ $doc?->displayName() ?? '—' }}</td>
                <td class="t-right">{{ Format::money($native) }}</td>
                <td class="t-right"><strong>{{ Format::money($pending) }}</strong></td>
                <td class="t-right">{{ Format::money($settled) }}</td>
                <td class="actions">
                    @if ($doc && $pending > 0)
                        <form method="post" action="{{ route('splits.settle', $doc) }}" data-confirm="Fechar o repasse de {{ $doc->displayName() }} ({{ Format::money($pending) }}) e gerar a conta a pagar?">
                            @csrf <input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}">
                            <button class="btn btn-sm btn-primary" type="submit">Fechar repasse</button></form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty">Nenhum repasse no período. Cadastre as regras abaixo — elas valem para os próximos recebimentos.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>

<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Regras de repasse</h2></div>
        <div class="card__body stack">
            @forelse ($rules as $rule)
                <div class="spread small {{ $rule->is_active ? '' : 'muted' }}">
                    <span><strong>{{ $rule->doctor->displayName() }}</strong>: {{ $rule->label() }}
                        · {{ $rule->service?->name ?? 'todos os atendimentos' }} · {{ ['private' => 'particular', 'insurance' => 'convênio'][$rule->payer_type] ?? 'qualquer pagador' }}
                        {{ $rule->is_active ? '' : '(inativa)' }}</span>
                    <form method="post" action="{{ route('splits.rules.toggle', $rule) }}">@csrf @method('patch')<button class="btn btn-sm btn-ghost" type="submit">{{ $rule->is_active ? 'Desativar' : 'Reativar' }}</button></form>
                </div>
            @empty
                <p class="small muted">Nenhuma regra. Sem regra, o recebimento fica 100% para a clínica.</p>
            @endforelse
            <form method="post" action="{{ route('splits.rules.store') }}" class="form-grid">
                @csrf
                <div class="field col-6"><label for="sr-d">Médico</label>
                    <select id="sr-d" name="doctor_id" class="input" required>@foreach ($doctors as $d)<option value="{{ $d->id }}">{{ $d->displayName() }}</option>@endforeach</select></div>
                <div class="field col-6"><label for="sr-s">Tipo de atendimento</label>
                    <select id="sr-s" name="doctor_service_id" class="input"><option value="">Todos</option>@foreach ($services as $s)<option value="{{ $s->id }}">{{ $byDoctor[$s->doctor_id]?->displayName() }} — {{ $s->name }}</option>@endforeach</select></div>
                <div class="field col-4"><label for="sr-p">Pagador</label>
                    <select id="sr-p" name="payer_type" class="input"><option value="">Qualquer</option><option value="private">Particular</option><option value="insurance">Convênio</option></select></div>
                <div class="field col-4"><label for="sr-t">Tipo</label>
                    <select id="sr-t" name="type" class="input"><option value="percent">Percentual (%)</option><option value="fixed">Valor fixo (R$)</option></select></div>
                <x-field name="value" label="Valor" col="col-4" placeholder="60,00" required />
                <p class="help col-12">A regra mais específica vale (tipo de atendimento + pagador &gt; tipo de atendimento &gt; pagador &gt; geral). Percentual sobre o valor recebido (no split nativo do ASAAS, sobre o valor líquido).</p>
                <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Adicionar regra</button></div>
            </form>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Carteiras ASAAS dos médicos (split nativo)</h2></div>
        <div class="card__body stack">
            <p class="help">Com a carteira (walletId) cadastrada, cobranças pelo ASAAS já depositam a parte do médico na conta dele. O médico precisa ter conta no ASAAS (ou subconta da clínica).</p>
            @foreach ($doctors as $d)
                <form method="post" action="{{ route('splits.wallet', $d) }}" class="row">
                    @csrf @method('put')
                    <span class="small grow">{{ $d->displayName() }}</span>
                    <label class="sr-only" for="w-{{ $d->id }}">walletId</label>
                    <input id="w-{{ $d->id }}" name="asaas_wallet_id" class="input input-sm w-auto mono" value="{{ $d->asaas_wallet_id }}" placeholder="walletId" maxlength="64">
                    <button class="btn btn-sm" type="submit">Salvar</button>
                </form>
            @endforeach
        </div>
    </section>
</div>

<section class="card mt-2">
    <div class="card__head"><h2>Últimos lançamentos de repasse</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Data</th><th>Médico</th><th>Referente a</th><th class="t-right">Base</th><th class="t-right">Parte do médico</th><th>Tipo</th><th>Situação</th></tr></thead>
        <tbody>
        @forelse ($recent as $s)
            <tr><td class="small nowrap">{{ $s->created_at->timezone($tz)->format('d/m/Y H:i') }}</td><td class="small">{{ $s->doctor->displayName() }}</td>
                <td class="small">{{ $s->receivable?->description }}</td><td class="t-right small">{{ Format::money($s->base_cents) }}</td>
                <td class="t-right {{ $s->amount_cents < 0 ? 'text-danger' : '' }}">{{ Format::money($s->amount_cents) }}</td>
                <td class="small">{{ $s->mode === 'native' ? 'nativo (gateway)' : 'interno' }}</td>
                <td><span class="badge {{ ['settled' => 'badge-success', 'pending' => 'badge-warning'][$s->status] ?? '' }}">{{ ['settled' => 'liquidado', 'pending' => 'a repassar', 'reversed' => 'estornado'][$s->status] }}</span></td></tr>
        @empty
            <tr><td colspan="7" class="empty">Nenhum lançamento.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endsection
