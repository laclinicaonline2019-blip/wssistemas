@extends('layouts.app', ['title' => 'Lote '.$batch->number])

@php use App\Core\Support\Format; use App\Modules\Insurance\Models\Guide; $billed = $guides->where('status', 'billed'); @endphp

@section('content')
@include('insurance._tabs')
<div class="page-head">
    <div><h1>Lote {{ $batch->number }} — {{ $batch->insurer->name }}</h1>
        <p>{{ $batch->branch->name }} · {{ Guide::TYPES[$batch->guide_type] }} · competência {{ $batch->competence }}
            <span class="badge {{ ['open' => 'badge-warning', 'closed' => 'badge-info', 'partial' => 'badge-warning', 'paid' => 'badge-success'][$batch->status] ?? '' }}">{{ $batch->statusLabel() }}</span></p></div>
    <div class="row">
        @if ($batch->xml)<a class="btn btn-primary" href="{{ route('batches.xml', $batch) }}"><svg><use href="#i-file"/></svg>Baixar XML TISS</a>@endif
        @if ($batch->receivable)<a class="btn" href="{{ route('receivables.show', $batch->receivable) }}">Conta a receber</a>@endif
        <a class="btn" href="{{ route('batches.index') }}">Voltar</a>
    </div>
</div>

<div class="grid grid-4">
    <div class="card kpi"><div class="kpi__label">Guias</div><div class="kpi__value">{{ $batch->guides_count }}</div><div class="kpi__hint">&nbsp;</div></div>
    <div class="card kpi"><div class="kpi__label">Faturado</div><div class="kpi__value">{{ Format::money($batch->total_cents) }}</div><div class="kpi__hint">{{ $batch->receivable ? 'vence '.$batch->receivable->due_date->format('d/m/Y') : '' }}&nbsp;</div></div>
    <div class="card kpi"><div class="kpi__label">Pago</div><div class="kpi__value kpi-pos">{{ Format::money($batch->paid_cents) }}</div><div class="kpi__hint">&nbsp;</div></div>
    <div class="card kpi"><div class="kpi__label">Glosado</div><div class="kpi__value">{{ Format::money($batch->glosa_cents) }}</div><div class="kpi__hint">&nbsp;</div></div>
</div>

@if ($batch->xml)
    <div class="alert alert-info mt-2">XML TISS {{ $batch->insurer->tiss_version }} gerado em {{ $batch->closed_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} e <strong>validado no schema oficial da ANS</strong>. Hash (epílogo): <span class="mono">{{ $batch->xml_hash }}</span>.
        Envie o arquivo no portal da operadora{{ $batch->insurer->portal_url ? '' : '' }} @if ($batch->insurer->portal_url)(<a href="{{ $batch->insurer->portal_url }}" target="_blank" rel="noopener noreferrer">abrir portal</a>)@endif e registre o protocolo.
        {{ $batch->protocol ? 'Protocolo: '.$batch->protocol : '' }}</div>
@endif
@if ($batch->status === 'cancelled')<div class="alert mt-2">Lote cancelado: {{ $batch->cancel_reason }}</div>@endif

<form method="post" action="{{ route('batches.return', $batch) }}" id="return-form">@csrf</form>
<section class="card mt-2">
    <div class="card__head"><h2>Guias do lote</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Guia</th><th>Atendimento</th><th>Paciente</th><th>Médico</th><th class="t-right">Valor</th><th>Situação</th>
            @if ($billed->isNotEmpty() && in_array($batch->status, ['closed', 'partial'], true))<th>Valor pago</th><th>Glosa (código / motivo)</th>@endif<th></th></tr></thead>
        <tbody>
        @foreach ($guides as $g)
            <tr>
                <td><a class="mono" href="{{ route('guides.show', $g) }}">{{ $g->number }}</a></td><td>{{ $g->attendance_date->format('d/m/Y') }}</td>
                <td>{{ $g->patient->displayName() }}</td><td>{{ $g->doctor->displayName() }}</td>
                <td class="t-right">{{ Format::money($g->total_cents) }}</td>
                <td>@include('insurance._guide-badge', ['g' => $g]) @if ($g->glosa_cents > 0)<div class="small muted">glosa {{ Format::money($g->glosa_cents) }}</div>@endif</td>
                @if ($billed->isNotEmpty() && in_array($batch->status, ['closed', 'partial'], true))
                    @if ($g->status === 'billed')
                        <td><label class="sr-only" for="rp-{{ $g->id }}">Valor pago</label><input id="rp-{{ $g->id }}" form="return-form" name="guides[{{ $g->id }}][paid]" class="input input-sm money-input" data-mask="money" inputmode="numeric" placeholder="{{ number_format($g->total_cents / 100, 2, ',', '.') }}"></td>
                        <td class="row"><label class="sr-only" for="rc-{{ $g->id }}">Código</label><input id="rc-{{ $g->id }}" form="return-form" name="guides[{{ $g->id }}][glosa_code]" class="input input-sm w-auto" maxlength="4" inputmode="numeric" placeholder="1001">
                            <label class="sr-only" for="rr-{{ $g->id }}">Motivo</label><input id="rr-{{ $g->id }}" form="return-form" name="guides[{{ $g->id }}][glosa_reason]" class="input input-sm" maxlength="255" placeholder="Motivo da glosa"></td>
                    @else<td></td><td></td>@endif
                @endif
                <td class="actions">@if ($batch->status === 'open')<form method="post" action="{{ route('batches.guides.destroy', [$batch, $g]) }}">@csrf @method('delete')<button class="btn btn-sm btn-ghost" type="submit">Retirar</button></form>@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</section>

<div class="grid grid-2 mt-2">
    @if ($batch->status === 'open')
        <section class="card"><div class="card__head"><h2>Fechar o lote</h2></div>
            <div class="card__body stack">
                <p class="small">Gera o XML TISS (validado no schema da ANS), marca as guias como faturadas e cria a conta a receber do convênio com vencimento em {{ $batch->insurer->payment_term_days }} dias.</p>
                <form method="post" action="{{ route('batches.close', $batch) }}" data-confirm="Fechar o lote {{ $batch->number }} com {{ $batch->guides_count }} guias ({{ Format::money($batch->total_cents) }})?">@csrf<button class="btn btn-primary" type="submit">Fechar lote e gerar XML</button></form>
            </div></section>
    @endif

    @if ($billed->isNotEmpty() && in_array($batch->status, ['closed', 'partial'], true))
        <section class="card"><div class="card__head"><h2>Registrar retorno da operadora</h2></div>
            <div class="card__body form-grid">
                <p class="help col-12">Preencha o valor pago de cada guia do demonstrativo (vazio = ainda sem retorno). A diferença para o valor da guia é a glosa — informe o código TISS (4 dígitos) ou o motivo.</p>
                <div class="field col-6"><label for="rt-m">Forma</label><select id="rt-m" name="method" form="return-form" class="input">@foreach ($methods as $k => $l)<option value="{{ $k }}" @selected($k === 'bank_transfer')>{{ $l }}</option>@endforeach</select></div>
                <div class="field col-6"><label for="rt-d">Data do crédito</label><input id="rt-d" type="date" name="paid_on" form="return-form" class="input" value="{{ now('America/Sao_Paulo')->toDateString() }}" required></div>
                <div class="col-12"><button class="btn btn-primary" type="submit" form="return-form">Registrar pagamento e glosas</button></div>
            </div></section>
    @endif

    @if (in_array($batch->status, ['closed', 'partial', 'paid'], true))
        <section class="card"><div class="card__head"><h2>Envio</h2></div>
            <form method="post" action="{{ route('batches.sent', $batch) }}" class="card__body form-grid">@csrf
                <x-field name="protocol" label="Protocolo de recebimento da operadora" col="col-8" :value="$batch->protocol" maxlength="40" required />
                <div class="col-4 form-actions"><button class="btn" type="submit">Salvar</button></div></form></section>
    @endif

    @if (in_array($batch->status, ['open', 'closed'], true) && $batch->paid_cents === 0)
        <section class="card"><div class="card__head"><h2>Cancelar lote</h2></div>
            <form method="post" action="{{ route('batches.cancel', $batch) }}" class="card__body row" data-confirm="Cancelar o lote {{ $batch->number }}? As guias voltam a ficar prontas.">@csrf
                <label class="sr-only" for="bc-r">Motivo</label><input id="bc-r" name="reason" class="input" minlength="5" maxlength="255" placeholder="Motivo" required>
                <button class="btn btn-danger btn-sm" type="submit">Cancelar lote</button></form></section>
    @endif
</div>
@endsection
