@extends('layouts.app', ['title' => 'Profissionais · Melhor Sprint'])

@php
    use App\Core\Support\Format;
    use App\Modules\Sprint\Models\SprintProfessional;
    $e = $edit;
    $splitValue = $e ? number_format($e->split_value / 100, 2, ',', '.') : '';
@endphp

@section('content')
@include('sprint._head', ['subtitle' => 'Médicos e parceiros que recebem parte das vendas'])

<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Profissionais cadastrados</h2>
            <form method="post" action="{{ route('sprint.professionals.import') }}">@csrf<button class="btn btn-sm" type="submit">Importar médicos</button></form></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Nome</th><th>Tipo</th><th>Divisão</th><th>Split nativo</th><th></th></tr></thead>
            <tbody>
            @forelse ($professionals as $p)
                <tr class="{{ $p->is_active ? '' : 'muted' }}">
                    <td><strong>{{ $p->name }}</strong>@if ($p->document)<br><span class="small muted">{{ strlen($p->document) === 14 ? Format::cnpj($p->document) : Format::cpf($p->document) }}</span>@endif</td>
                    <td class="small">{{ SprintProfessional::KINDS[$p->kind] }}</td>
                    <td>{{ $p->splitLabel() }}</td>
                    <td class="small">{{ collect([$p->asaas_wallet_id ? 'ASAAS' : null, $p->cielo_subordinate_id ? 'Cielo' : null])->filter()->implode(' · ') ?: 'repasse interno' }}</td>
                    <td class="actions"><div class="stack">
                        <a class="btn btn-sm" href="{{ route('sprint.professionals.index', ['editar' => $p->id]) }}">Editar</a>
                        <form method="post" action="{{ route('sprint.professionals.toggle', $p) }}">@csrf @method('patch')<button class="btn btn-sm btn-ghost" type="submit">{{ $p->is_active ? 'Desativar' : 'Reativar' }}</button></form>
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">Nenhum profissional. Cadastre ao lado ou importe os médicos.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="card">
        <div class="card__head"><h2>{{ $e ? 'Editar '.$e->name : 'Novo profissional' }}</h2>@if ($e)<a class="btn btn-sm" href="{{ route('sprint.professionals.index') }}">Novo</a>@endif</div>
        <form method="post" action="{{ $e ? route('sprint.professionals.update', $e) : route('sprint.professionals.store') }}" class="card__body form-grid">
            @csrf @if ($e) @method('put') @endif
            <div class="field col-4"><label for="p-kind">Tipo</label>
                <select id="p-kind" name="kind" class="input">@foreach (SprintProfessional::KINDS as $k => $l)<option value="{{ $k }}" @selected(old('kind', $e?->kind ?? 'doctor') === $k)>{{ $l }}</option>@endforeach</select></div>
            <x-field name="name" label="Nome" col="col-8" maxlength="150" :value="$e?->name" required />
            <div class="field col-6"><label for="p-doc">Vínculo com médico (opcional)</label>
                <select id="p-doc" name="doctor_id" class="input"><option value="">—</option>@foreach ($doctors as $d)<option value="{{ $d->id }}" @selected(old('doctor_id', $e?->doctor_id) === $d->id)>{{ $d->displayName() }}</option>@endforeach</select></div>
            <x-field name="document" label="CPF ou CNPJ" col="col-6" maxlength="20" :value="$e?->document" />
            <x-field name="email" label="E-mail" type="email" col="col-6" maxlength="150" :value="$e?->email" />
            <x-field name="phone" label="Telefone" col="col-6" mask="phone" maxlength="20" :value="$e?->phone" />

            <h3 class="section-title">Regra de divisão (padrão nas vendas)</h3>
            <div class="field col-6"><label for="p-st">Tipo</label>
                <select id="p-st" name="split_type" class="input"><option value="percent" @selected(old('split_type', $e?->split_type) !== 'fixed')>Percentual do profissional (%)</option><option value="fixed" @selected(old('split_type', $e?->split_type) === 'fixed')>Valor fixo por item (R$)</option></select></div>
            <x-field name="split_value" label="Parte do profissional" col="col-6" mask="money" inputmode="numeric" :value="$splitValue" placeholder="60,00" required help="Ex.: 60,00 = 60% para o profissional e 40% para a clínica." />

            <h3 class="section-title">Recebimento</h3>
            <x-field name="pix_key" label="Chave PIX (repasse interno)" col="col-6" maxlength="140" :value="$e?->pix_key" />
            <x-field name="bank_info" label="Dados bancários" col="col-6" maxlength="255" :value="$e?->bank_info" />
            <x-field name="asaas_wallet_id" label="walletId ASAAS (split automático)" col="col-6" maxlength="64" :value="$e?->asaas_wallet_id" />
            <x-field name="cielo_subordinate_id" label="ID de subordinado Cielo" col="col-6" maxlength="36" :value="$e?->cielo_subordinate_id" />
            <p class="help col-12">Com <strong>walletId ASAAS</strong>, PIX e links do ASAAS já depositam a parte do profissional na conta dele. Com <strong>subordinado Cielo</strong>, o cartão digitado (Cielo online) e a maquininha Cielo com split também. Sem isso, a parte vira <strong>repasse interno</strong>, pago no fechamento.</p>
            <x-field name="notes" label="Observações" col="col-12" maxlength="500" :value="$e?->notes" />
            <div class="col-12 form-actions"><button class="btn btn-sprint" type="submit">{{ $e ? 'Salvar alterações' : 'Cadastrar profissional' }}</button></div>
        </form>
    </section>
</div>
@endsection
