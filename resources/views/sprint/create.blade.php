@extends('layouts.app', ['title' => 'Nova venda · Melhor Sprint'])

@section('content')
@include('sprint._head', ['subtitle' => 'Nova venda — informe os serviços e quem recebe cada parte'])

@if ($professionals->isEmpty())
    <div class="alert alert-info">Nenhum profissional cadastrado: os itens ficam 100% da clínica.
        @if (auth()->user()->hasPermission('sprint.profissionais'))<a href="{{ route('sprint.professionals.index') }}">Cadastrar médicos e parceiros</a>.@endif</div>
@endif

<form method="post" action="{{ route('sprint.sales.store') }}" class="card" data-sprint-sale>
    @csrf
    <div class="card__body form-grid">
        @if (auth()->user()->hasPermission('paciente.visualizar') || auth()->user()->hasPermission('agenda.criar'))
        <div class="field col-6 lookup" data-patient-lookup="{{ route('agenda.patient_lookup') }}">
            <label for="patient-q">Paciente (obrigatório para PIX/cartão pelo ASAAS e NFS-e)</label>
            <input type="hidden" name="patient_id" id="patient_id" value="{{ old('patient_id', $patient?->id) }}">
            <div class="selected-patient {{ $patient ? '' : 'hidden' }}" id="patient-selected"><div><strong id="patient-selected-name">{{ $patient?->displayName() }}</strong> <span class="small muted" id="patient-selected-info">{{ $patient ? '#'.$patient->record_number : '' }}</span></div>
                <button type="button" class="btn btn-sm btn-ghost" data-patient-clear>Trocar</button></div>
            <input id="patient-q" class="input {{ $patient ? 'hidden' : '' }}" placeholder="Nome, CPF ou nº do prontuário" autocomplete="off">
            <div id="patient-results" class="lookup__list hidden"></div>
            <select id="patient_insurance_id" class="hidden" aria-hidden="true" tabindex="-1"></select><select class="hidden" data-payer aria-hidden="true" tabindex="-1"><option value="private">p</option></select><div id="insurance-field" class="hidden"></div>
        </div>
        @endif
        <x-field name="customer_name" label="Ou nome do cliente (sem cadastro)" col="col-3" maxlength="150" />
        <x-field name="customer_document" label="CPF/CNPJ do cliente" col="col-3" maxlength="20" />
        @if ($branches->count() > 1)
            <div class="field col-4"><label for="s-br">Unidade</label>
                <select id="s-br" name="branch_id" class="input">@foreach ($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', app(\App\Core\Tenancy\TenantContext::class)->branchId()) === $b->id)>{{ $b->name }}</option>@endforeach</select></div>
        @endif
        <x-field name="description" label="Descrição da venda (opcional)" :col="$branches->count() > 1 ? 'col-8' : 'col-12'" maxlength="200" />
    </div>

    <div class="card__body stack">
        <div class="spread"><h2 class="section-title">Itens e divisão</h2><button type="button" class="btn btn-sm" data-add-item>+ Adicionar item</button></div>
        <div class="ms-items" data-items>
            @foreach (old('items', [[]]) as $i => $row)
                @include('sprint._item', ['i' => $i, 'row' => $row])
            @endforeach
        </div>
        <template id="ms-item-template">@include('sprint._item', ['i' => '__i__', 'row' => []])</template>
        <p class="help">A divisão vem do cadastro do profissional e pode ser ajustada nesta venda. Percentual = parte do profissional sobre o item; o restante fica para a clínica.
            Em pagamentos mistos, cada pagamento é dividido na mesma proporção.</p>

        <div class="ms-summary" aria-live="polite">
            <div><span class="small muted">Total da venda</span><strong data-sum-total>R$ 0,00</strong></div>
            <div class="ms-pro"><span class="small muted">Profissionais</span><strong data-sum-pro>R$ 0,00</strong></div>
            <div class="ms-house"><span class="small muted">Clínica (base da NFS-e)</span><strong data-sum-house>R$ 0,00</strong></div>
        </div>

        @if ($settings->nfse_enabled)
            <label class="check"><input type="hidden" name="issue_nfse" value="0"><input type="checkbox" name="issue_nfse" value="1" @checked(old('issue_nfse', true))>
                <span><strong>Emitir NFS-e</strong> da parte da clínica {{ $settings->nfse_auto ? '(automática quando a venda for quitada)' : '(pelo botão na venda, após quitar)' }}.</span></label>
        @endif
    </div>
    <div class="card__body form-actions"><a class="btn" href="{{ route('sprint.index') }}">Voltar</a><button class="btn btn-sprint" type="submit">Continuar para o pagamento</button></div>
</form>
@endsection
