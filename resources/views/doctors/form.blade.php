@extends('layouts.app', ['title' => $doctor->exists ? $doctor->displayName() : 'Novo médico'])

@php
    use App\Core\Support\Format;
    $editable = $canManage && auth()->user()->hasPermission('medico.gerenciar');
    $selectedSpecialties = old('specialty_ids', $doctor->exists ? $doctor->specialties->pluck('id')->all() : []);
    $rqe = old('rqe', $doctor->exists ? $doctor->specialties->mapWithKeys(fn ($s) => [$s->id => $s->pivot->rqe])->all() : []);
    $selectedBranches = old('branches', $doctor->exists ? $doctor->branches->pluck('id')->all() : []);
@endphp

@section('content')
<div class="page-head">
    <div><h1>{{ $doctor->exists ? $doctor->displayName() : 'Novo médico' }}</h1>
        <p>{{ $doctor->exists ? $doctor->registration() : 'Cadastro do profissional. A agenda (horários e limites) é configurada na Fase 4.' }}</p></div>
    <a class="btn" href="{{ route('doctors.index') }}">Voltar</a>
</div>

@if ($doctor->exists && ! $canManage)
    <div class="alert alert-info">Este médico também atende em unidades fora da sua gestão — somente visualização.</div>
@endif

<form method="post" action="{{ $doctor->exists ? route('doctors.update', $doctor) : route('doctors.store') }}">
    @csrf
    @if ($doctor->exists) @method('put') @endif
    <fieldset class="plain" @disabled(! $editable)>
    <div class="grid grid-2">
        <section class="card">
            <div class="card__head"><h2>Identificação profissional</h2></div>
            <div class="card__body form-grid">
                <x-field name="name" label="Nome completo" :value="$doctor->name" col="col-12" required />
                <x-field name="social_name" label="Nome social / nome de exibição" :value="$doctor->social_name" col="col-12" />
                <x-field name="crm" label="CRM" :value="$doctor->crm" col="col-6" required inputmode="numeric" />
                <div class="field col-6"><label for="f-crm_state">UF do CRM *</label>
                    <select id="f-crm_state" name="crm_state" class="input @error('crm_state') is-invalid @enderror" required><option value="">—</option>
                        @foreach (\App\Core\Validation\BrazilianStates::ALL as $uf)<option @selected(old('crm_state', $doctor->crm_state) === $uf)>{{ $uf }}</option>@endforeach
                    </select>@error('crm_state')<div class="field-error">{{ $message }}</div>@enderror</div>
                <x-field name="cpf" label="CPF" :value="Format::cpf($doctor->cpf)" col="col-6" mask="cpf" />
                <x-field name="phone" label="Telefone" :value="Format::phone($doctor->phone)" col="col-6" mask="phone" />
                <x-field name="email" label="E-mail" type="email" :value="$doctor->email" col="col-12" />
                <div class="field col-12"><label for="f-user">Conta de acesso ao sistema</label>
                    <select id="f-user" name="user_id" class="input"><option value="">Sem acesso (apenas cadastro)</option>
                        @foreach ($users as $u)<option value="{{ $u->id }}" @selected(old('user_id', $doctor->user_id) === $u->id)>{{ $u->name }} — {{ $u->email }}</option>@endforeach
                    </select>
                    <div class="help">Vincule o usuário do médico (com perfil "Médico") para ele acessar agenda e prontuário.</div></div>
                <div class="field col-12"><label for="f-bio">Apresentação</label>
                    <textarea id="f-bio" name="bio" class="input" rows="3" maxlength="1000" placeholder="Formação, áreas de atuação. Usada no portal do paciente e pela recepcionista virtual.">{{ old('bio', $doctor->bio) }}</textarea></div>
            </div>
        </section>

        <div>
            <section class="card">
                <div class="card__head"><h2>Especialidades</h2></div>
                <div class="card__body stack">
                    @error('specialties')<div class="field-error">{{ $message }}</div>@enderror
                    @forelse ($specialties as $s)
                        <div class="spread">
                            <label class="check"><input type="checkbox" name="specialty_ids[]" value="{{ $s->id }}" @checked(in_array($s->id, $selectedSpecialties, true))><span>{{ $s->name }}</span></label>
                            <label class="sr-only" for="rqe-{{ $s->id }}">RQE {{ $s->name }}</label>
                            <input id="rqe-{{ $s->id }}" name="rqe[{{ $s->id }}]" class="input input-sm" placeholder="RQE" maxlength="20" value="{{ $rqe[$s->id] ?? '' }}">
                        </div>
                    @empty
                        <p class="muted small">Nenhuma especialidade ativa. <a href="{{ route('specialties.index') }}">Cadastrar</a></p>
                    @endforelse
                </div>
            </section>
            <section class="card">
                <div class="card__head"><h2>Unidades de atendimento</h2></div>
                <div class="card__body stack">
                    @error('branches')<div class="field-error">{{ $message }}</div>@enderror
                    @foreach ($branches as $b)
                        <label class="check"><input type="checkbox" name="branches[]" value="{{ $b->id }}" @checked(in_array($b->id, $selectedBranches, true))><span>{{ $b->name }}</span></label>
                    @endforeach
                </div>
            </section>
        </div>
    </div>
    </fieldset>

    @if ($editable)
        <div class="form-actions"><button class="btn btn-primary" type="submit">{{ $doctor->exists ? 'Salvar alterações' : 'Cadastrar médico' }}</button></div>
    @endif
</form>

@if ($doctor->exists && $editable)
    <form method="post" action="{{ route('doctors.status', $doctor) }}" class="mt-2" data-confirm="{{ $doctor->status === 'active' ? 'Desativar este médico? Ele deixará de aparecer para agendamento.' : 'Reativar este médico?' }}">
        @csrf @method('patch')
        <input type="hidden" name="status" value="{{ $doctor->status === 'active' ? 'inactive' : 'active' }}">
        <button class="btn {{ $doctor->status === 'active' ? 'btn-danger' : '' }}" type="submit">{{ $doctor->status === 'active' ? 'Desativar médico' : 'Reativar médico' }}</button>
    </form>
@endif
@endsection
