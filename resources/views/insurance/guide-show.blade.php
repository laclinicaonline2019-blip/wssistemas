@extends('layouts.app', ['title' => 'Guia '.$guide->number])

@php
    use App\Core\Support\Format;
    use App\Modules\Insurance\Models\Guide;
    use App\Modules\Insurance\Models\Procedure;
    $editable = $guide->isEditable();
    $methods = array_diff_key(\App\Modules\Finance\Models\FinancialTransaction::METHODS, ['cash' => 1]);
@endphp

@section('content')
@include('insurance._tabs')
<div class="page-head">
    <div>
        <h1>Guia {{ $guide->number }} — {{ $guide->guide_type === 'consulta' ? 'Consulta' : 'SP/SADT' }}</h1>
        <p>{{ $guide->insurer->name }}{{ $guide->plan ? ' · '.$guide->plan->name : '' }} · {{ $guide->branch->name }} · atendimento {{ $guide->attendance_date->format('d/m/Y') }}
            @include('insurance._guide-badge', ['g' => $guide])</p>
    </div>
    <div class="row">
        <a class="btn" href="{{ route('guides.print', $guide) }}" target="_blank" rel="noopener"><svg><use href="#i-printer"/></svg>Imprimir</a>
        @if ($guide->batch)<a class="btn" href="{{ route('batches.show', $guide->batch) }}">Lote {{ $guide->batch->number }}</a>@endif
        <a class="btn" href="{{ route('guides.index') }}">Voltar</a>
    </div>
</div>

@if ($editable && $issues)
    <div class="alert alert-warning"><strong>Pendências para faturar:</strong><ul class="mb-0">@foreach ($issues as $i)<li>{{ $i }}</li>@endforeach</ul></div>
@endif
@if ($guide->status === 'cancelled')<div class="alert">Guia cancelada: {{ $guide->cancel_reason }}</div>@endif

<div class="grid grid-4">
    <div class="card kpi"><div class="kpi__label">Valor da guia</div><div class="kpi__value">{{ Format::money($guide->total_cents) }}</div><div class="kpi__hint">tabela do convênio</div></div>
    <div class="card kpi"><div class="kpi__label">Pago pelo convênio</div><div class="kpi__value kpi-pos">{{ Format::money($guide->paid_cents) }}</div><div class="kpi__hint">&nbsp;</div></div>
    <div class="card kpi"><div class="kpi__label">Glosa</div><div class="kpi__value">{{ Format::money($guide->glosa_cents) }}</div><div class="kpi__hint">{{ $guide->glosa_status ? Guide::GLOSA_STATUSES[$guide->glosa_status] : '' }}&nbsp;</div></div>
    <div class="card kpi"><div class="kpi__label">Paciente (particular)</div><div class="kpi__value">{{ Format::money((int) $patientCharges->where('status', '!=', 'cancelled')->sum('amount_cents')) }}</div><div class="kpi__hint">coparticipação / não coberto</div></div>
</div>

<div class="grid grid-2 mt-2">
    <section class="card">
        <div class="card__head"><h2>Beneficiário e execução</h2></div>
        <div class="card__body">
            <dl class="dl">
                <dt>Paciente</dt><dd><a href="{{ route('patients.show', $guide->patient) }}">{{ $guide->patient->displayName() }}</a> · #{{ $guide->patient->record_number }}</dd>
                <dt>Carteirinha</dt><dd class="mono">{{ $guide->card_number }}{{ $guide->card_valid_until ? ' · validade '.$guide->card_valid_until->format('d/m/Y') : '' }}</dd>
                <dt>Médico</dt><dd>{{ $guide->doctor->displayName() }} · CRM {{ $guide->doctor->crm }}/{{ $guide->doctor->crm_state }} · CBO {{ $guide->cbo_code }}</dd>
                @if ($guide->appointment)<dt>Agendamento</dt><dd><a href="{{ route('agenda.show', $guide->appointment) }}">{{ $guide->appointment->protocol }}</a></dd>@endif
                <dt>Autorização</dt><dd>{{ $guide->authorization ? 'Senha '.($guide->authorization->password ?? '—').' · '.$guide->authorization->procedure->code.' · '.$guide->authorization->statusLabel() : '—' }}</dd>
                @if ($guide->glosa_reason)<dt>Motivo da glosa</dt><dd>{{ $guide->glosa_code ? $guide->glosa_code.' — ' : '' }}{{ $guide->glosa_reason }}</dd>@endif
                @if ($guide->appeal_text)<dt>Recurso</dt><dd>{{ $guide->appeal_text }}</dd>@endif
            </dl>
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Dados TISS da guia</h2></div>
        <form method="post" action="{{ route('guides.update', $guide) }}" class="card__body form-grid">
            @csrf @method('put')
            <fieldset class="col-12 form-grid" @disabled(! $editable)>
                <x-field name="operator_guide_number" label="Nº da guia na operadora" col="col-6" :value="$guide->operator_guide_number" maxlength="20" />
                <div class="field col-6"><label for="g-auth">Autorização</label>
                    <select id="g-auth" name="authorization_id" class="input"><option value="">—</option>@foreach ($authorizations as $a)<option value="{{ $a->id }}" @selected($guide->authorization_id === $a->id)>{{ $a->procedure->code }} · senha {{ $a->password ?? '—' }} · {{ $a->statusLabel() }}{{ $a->valid_until ? ' até '.$a->valid_until->format('d/m/Y') : '' }}</option>@endforeach</select></div>
                <div class="field col-6"><label for="g-ct">Tipo de consulta</label><select id="g-ct" name="consultation_type" class="input">@foreach (Guide::CONSULTATION_TYPES as $k => $l)<option value="{{ $k }}" @selected($guide->consultation_type === (string) $k)>{{ $l }}</option>@endforeach</select></div>
                <div class="field col-6"><label for="g-at">Tipo de atendimento (SP/SADT)</label><select id="g-at" name="attendance_type" class="input">@foreach (Procedure::KINDS as $k)<option value="{{ $k['attendance_type'] }}" @selected($guide->attendance_type === $k['attendance_type'])>{{ $k['attendance_type'] }} — {{ $k['label'] }}</option>@endforeach</select></div>
                <div class="field col-6"><label for="g-ac">Indicação de acidente</label><select id="g-ac" name="accident_indicator" class="input">@foreach (Guide::ACCIDENT as $k => $l)<option value="{{ $k }}" @selected($guide->accident_indicator === (string) $k)>{{ $l }}</option>@endforeach</select></div>
                <div class="field col-3"><label for="g-ch">Caráter</label><select id="g-ch" name="character" class="input"><option value="1" @selected($guide->character === '1')>Eletivo</option><option value="2" @selected($guide->character === '2')>Urgência</option></select></div>
                <x-field name="cbo_code" label="CBO" col="col-3" :value="$guide->cbo_code" maxlength="6" inputmode="numeric" required />
                <x-field name="clinical_indication" label="Indicação clínica (SP/SADT)" col="col-12" :value="$guide->clinical_indication" maxlength="500" />
                <x-field name="observation" label="Observação" col="col-12" :value="$guide->observation" maxlength="500" />
                @if ($editable)<div class="col-12 form-actions"><button class="btn" type="submit">Salvar dados</button></div>@endif
            </fieldset>
        </form>
    </section>
</div>

<section class="card mt-2">
    <div class="card__head"><h2>Procedimentos realizados</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Data</th><th>Código</th><th>Descrição</th><th class="t-right">Qtde</th><th class="t-right">Unitário</th><th class="t-right">Total</th><th></th></tr></thead>
        <tbody>
        @forelse ($guide->items as $it)
            <tr>
                <td>{{ $it->execution_date->format('d/m/Y') }}</td><td class="mono">{{ $it->table_code }} · {{ $it->code }}</td>
                <td>{{ $it->description }} @if ($it->requires_authorization)<span class="badge badge-warning">exige autorização</span>@endif</td>
                <td class="t-right">{{ $it->quantity }}</td><td class="t-right">{{ Format::money($it->unit_cents) }}</td><td class="t-right">{{ Format::money($it->total_cents) }}</td>
                <td class="actions">@if ($editable)<form method="post" action="{{ route('guides.items.destroy', [$guide, $it]) }}">@csrf @method('delete')<button class="btn btn-sm btn-ghost" type="submit">Retirar</button></form>@endif</td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">Nenhum procedimento.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    @if ($editable && ! ($guide->guide_type === 'consulta' && $guide->items->isNotEmpty()))
        <form method="post" action="{{ route('guides.items.store', $guide) }}" class="card__body form-grid">
            @csrf
            <div class="field col-6"><label for="gi-p">Procedimento</label><select id="gi-p" name="procedure_id" class="input" required>@foreach ($procedures as $p)<option value="{{ $p->id }}">{{ $p->code }} — {{ $p->name }}</option>@endforeach</select></div>
            <x-field name="quantity" label="Qtde" type="number" min="1" max="999" col="col-2" :value="1" required />
            <x-field name="execution_date" label="Data de execução" type="date" col="col-4" :value="$guide->attendance_date->toDateString()" required />
            <p class="help col-12">O valor vem da tabela vigente do convênio/plano na data. Coparticipação da tabela gera cobrança particular do paciente automaticamente.</p>
            <div class="col-12 form-actions"><button class="btn" type="submit">Incluir procedimento</button></div>
        </form>
    @endif
</section>

<div class="grid grid-2 mt-2">
    <section class="card">
        <div class="card__head"><h2>Ações</h2></div>
        <div class="card__body stack">
            @if ($editable && $guide->status === 'draft')
                <form method="post" action="{{ route('guides.ready', $guide) }}">@csrf<button class="btn btn-primary" type="submit" @disabled($issues)>Conferida — pronta para faturar</button></form>
            @elseif ($editable && $guide->status === 'ready')
                <p class="small">Pronta. Inclua em um lote em <a href="{{ route('batches.index') }}">Lotes e glosas</a>.</p>
                <form method="post" action="{{ route('guides.draft', $guide) }}">@csrf<button class="btn btn-sm" type="submit">Voltar para rascunho</button></form>
            @endif
            @if ($editable)
                <form method="post" action="{{ route('guides.cancel', $guide) }}" class="row" data-confirm="Cancelar a guia {{ $guide->number }}?">
                    @csrf <label class="sr-only" for="gc-r">Motivo</label><input id="gc-r" name="reason" class="input" placeholder="Motivo do cancelamento" minlength="5" required>
                    <button class="btn btn-sm btn-danger" type="submit">Cancelar guia</button></form>
            @endif

            @if (in_array($guide->glosa_status, ['pending', 'appealed'], true))
                <h3>Glosa de {{ Format::money($guide->glosa_cents) }}</h3>
                @if ($guide->glosa_status === 'pending')
                    <form method="post" action="{{ route('guides.glosa', $guide) }}" class="stack">@csrf <input type="hidden" name="action" value="appeal">
                        <label for="ap-t">Justificativa do recurso</label><textarea id="ap-t" name="appeal_text" class="input" rows="3" minlength="10" maxlength="1000" required></textarea>
                        <button class="btn btn-sm" type="submit">Registrar recurso de glosa</button></form>
                @else
                    <form method="post" action="{{ route('guides.glosa', $guide) }}" class="form-grid">@csrf <input type="hidden" name="action" value="recover">
                        <x-field name="recovered" label="Valor recuperado no recurso (R$)" col="col-6" mask="money" inputmode="numeric" required />
                        <x-field name="paid_on" label="Data do pagamento" type="date" col="col-6" :value="now('America/Sao_Paulo')->toDateString()" />
                        <div class="field col-6"><label for="gr-m">Forma</label><select id="gr-m" name="method" class="input">@foreach ($methods as $k => $l)<option value="{{ $k }}" @selected($k === 'bank_transfer')>{{ $l }}</option>@endforeach</select></div>
                        <p class="help col-12">O que não foi recuperado é baixado como glosa definitiva.</p>
                        <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Concluir recurso</button></div></form>
                @endif
                <form method="post" action="{{ route('guides.glosa', $guide) }}" data-confirm="Aceitar a glosa de {{ Format::money($guide->glosa_cents) }}? O valor deixa de ser cobrado do convênio.">@csrf
                    <input type="hidden" name="action" value="accept"><button class="btn btn-sm btn-ghost" type="submit">Aceitar glosa (não recorrer)</button></form>
            @endif
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Cobrança do paciente (atendimento misto)</h2></div>
        <div class="card__body stack">
            @foreach ($patientCharges as $r)
                <div class="spread small"><a href="{{ route('receivables.show', $r) }}">{{ $r->description }}</a><span>{{ Format::money($r->amount_cents) }} · {{ $r->statusLabel() }}</span></div>
            @endforeach
            @if ($guide->status !== 'cancelled')
                <form method="post" action="{{ route('guides.charge_patient', $guide) }}" class="form-grid">
                    @csrf
                    <x-field name="description" label="Item não coberto pelo convênio" col="col-8" maxlength="120" placeholder="Ex.: material descartável" required />
                    <x-field name="amount" label="Valor (R$)" col="col-4" mask="money" inputmode="numeric" required />
                    <div class="col-12"><button class="btn btn-sm" type="submit">Cobrar do paciente como particular</button></div>
                </form>
            @endif
        </div>
    </section>
</div>
@endsection
