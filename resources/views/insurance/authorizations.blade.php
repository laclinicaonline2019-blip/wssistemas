@extends('layouts.app', ['title' => 'Autorizações'])

@php use App\Modules\Insurance\Models\Authorization; @endphp

@section('content')
@include('insurance._tabs')
<div class="page-head">
    <div><h1>Autorizações prévias</h1><p>Procedimentos que a operadora exige autorizar antes (senha). A guia só fica pronta com a autorização válida vinculada.</p></div>
    <form method="get" class="row"><label class="sr-only" for="as">Situação</label>
        <select id="as" name="status" class="input w-auto">@foreach (['requested' => 'Aguardando resposta', 'authorized' => 'Autorizadas', 'denied' => 'Negadas', 'used' => 'Utilizadas', 'cancelled' => 'Canceladas', 'all' => 'Todas'] as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach</select>
        <button class="btn" type="submit">Filtrar</button></form>
</div>

<section class="card mb-2">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Solicitada</th><th>Paciente</th><th>Convênio</th><th>Procedimento</th><th>Situação</th><th>Senha / validade</th><th></th></tr></thead>
        <tbody>
        @forelse ($items as $a)
            <tr>
                <td class="nowrap small">{{ $a->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                <td><a href="{{ route('patients.show', $a->patient_id) }}">{{ $a->patient->displayName() }}</a></td>
                <td>{{ $a->insurer->name }}</td>
                <td><span class="mono">{{ $a->procedure->code }}</span> {{ $a->procedure->name }} × {{ $a->quantity }}</td>
                <td><span class="badge {{ ['authorized' => 'badge-success', 'denied' => 'badge-danger', 'requested' => 'badge-warning'][$a->status] ?? '' }}">{{ $a->statusLabel() }}</span>
                    @if ($a->denial_reason)<div class="small muted">{{ $a->denial_reason }}</div>@endif</td>
                <td class="small mono">{{ $a->password ?? '—' }}{{ $a->operator_guide_number ? ' · guia '.$a->operator_guide_number : '' }}{{ $a->valid_until ? ' · até '.$a->valid_until->format('d/m/Y') : '' }}</td>
                <td class="actions">
                    @if ($a->status === 'requested')
                        <details><summary class="btn btn-sm">Registrar resposta</summary>
                            <form method="post" action="{{ route('authorizations.decide', $a) }}" class="form-grid mt-1">
                                @csrf
                                <div class="field col-12"><label for="d-{{ $a->id }}">Resposta</label><select id="d-{{ $a->id }}" name="decision" class="input"><option value="authorized">Autorizada</option><option value="denied">Negada</option></select></div>
                                <div class="field col-6"><label for="pw-{{ $a->id }}">Senha</label><input id="pw-{{ $a->id }}" name="password" class="input" maxlength="20"></div>
                                <div class="field col-6"><label for="og-{{ $a->id }}">Nº guia operadora</label><input id="og-{{ $a->id }}" name="operator_guide_number" class="input" maxlength="20" value="{{ $a->operator_guide_number }}"></div>
                                <div class="field col-6"><label for="vu-{{ $a->id }}">Validade da senha</label><input id="vu-{{ $a->id }}" type="date" name="valid_until" class="input"></div>
                                <div class="field col-6"><label for="dr-{{ $a->id }}">Motivo (se negada)</label><input id="dr-{{ $a->id }}" name="denial_reason" class="input" maxlength="255"></div>
                                <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Salvar</button></div>
                            </form></details>
                    @endif
                    @if (in_array($a->status, ['requested', 'authorized'], true))
                        <form method="post" action="{{ route('authorizations.cancel', $a) }}" data-confirm="Cancelar esta autorização?">@csrf<button class="btn btn-sm btn-ghost" type="submit">Cancelar</button></form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">Nenhuma autorização nesta situação.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    @include('partials.pagination', ['paginator' => $items])
</section>

<section class="card">
    <div class="card__head"><h2>Nova solicitação</h2></div>
    <div class="card__body">
        @if (! $patient)
            @include('insurance._patient-pick')
        @elseif ($patient->insurances->whereNotNull('insurer_id')->isEmpty())
            <p class="small">{{ $patient->displayName() }} não tem carteirinha de convênio cadastrado. <a href="{{ route('patients.edit', $patient) }}">Editar cadastro</a></p>
        @else
            <form method="post" action="{{ route('authorizations.store') }}" class="form-grid">
                @csrf
                <input type="hidden" name="patient_id" value="{{ $patient->id }}">
                <p class="col-12"><strong>{{ $patient->displayName() }}</strong> · prontuário #{{ $patient->record_number }} · <a href="{{ route('authorizations.index') }}">trocar</a></p>
                <div class="field col-6"><label for="au-ins">Carteirinha</label>
                    <select id="au-ins" name="patient_insurance_id" class="input" required>@foreach ($patient->insurances->whereNotNull('insurer_id') as $i)<option value="{{ $i->id }}">{{ $i->insurer_name }} · {{ $i->card_number }}</option>@endforeach</select></div>
                <div class="field col-6"><label for="au-br">Unidade</label><select id="au-br" name="branch_id" class="input">@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
                <div class="field col-8"><label for="au-proc">Procedimento</label><select id="au-proc" name="procedure_id" class="input" required>@foreach ($procedures as $p)<option value="{{ $p->id }}">{{ $p->code }} — {{ $p->name }}</option>@endforeach</select></div>
                <x-field name="quantity" label="Quantidade" type="number" min="1" max="999" col="col-4" :value="1" required />
                <div class="field col-6"><label for="au-doc">Médico (opcional)</label><select id="au-doc" name="doctor_id" class="input"><option value="">—</option>@foreach ($doctors as $d)<option value="{{ $d->id }}">{{ $d->displayName() }}</option>@endforeach</select></div>
                <x-field name="operator_guide_number" label="Nº do pedido/guia na operadora" col="col-6" maxlength="20" />
                <x-field name="notes" label="Observações" col="col-12" maxlength="500" />
                <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Registrar solicitação</button></div>
            </form>
        @endif
    </div>
</section>
@endsection
