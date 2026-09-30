@extends('layouts.app', ['title' => 'Nova clínica'])

@section('content')
<div class="page-head">
    <div><h1>Provisionar nova clínica</h1><p>Cria a empresa, a matriz, os perfis padrão e o administrador em uma única operação.</p></div>
    <a class="btn" href="{{ route('platform.companies.index') }}">Voltar</a>
</div>

<form method="post" action="{{ route('platform.companies.store') }}">
    @csrf
    <div class="grid grid-2">
        <section class="card">
            <div class="card__head"><h2>Empresa</h2></div>
            <div class="card__body form-grid">
                <x-field name="legal_name" label="Razão social" col="col-12" required />
                <x-field name="trade_name" label="Nome fantasia" col="col-6" required />
                <x-field name="document" label="CNPJ" col="col-6" mask="cnpj" required />
                <x-field name="email" label="E-mail" type="email" col="col-6" />
                <x-field name="phone" label="Telefone" col="col-6" mask="phone" />
                <div class="field col-6"><label for="plan">Plano</label>
                    <select id="plan" name="saas_plan_id" class="input"><option value="">Sem plano</option>
                        @foreach ($plans as $p)<option value="{{ $p->id }}" @selected(old('saas_plan_id') === $p->id)>{{ $p->name }} — R$ {{ number_format($p->price_monthly_cents / 100, 2, ',', '.') }}/mês</option>@endforeach
                    </select></div>
                <div class="field col-6"><label for="st">Status inicial</label>
                    <select id="st" name="status" class="input"><option value="trial">Trial</option><option value="active" @selected(old('status') === 'active')>Ativa</option></select></div>
            </div>
        </section>
        <section class="card">
            <div class="card__head"><h2>Matriz e administrador</h2></div>
            <div class="card__body form-grid">
                <x-field name="headquarters_name" label="Nome da matriz" col="col-12" required value="Matriz" />
                <x-field name="city" label="Cidade" col="col-8" />
                <x-field name="state" label="UF" col="col-4" maxlength="2" />
                <x-field name="admin_name" label="Nome do administrador" col="col-12" required />
                <x-field name="admin_email" label="E-mail do administrador" type="email" col="col-6" required />
                <x-field name="admin_password" label="Senha provisória" type="password" col="col-6" required autocomplete="new-password" help="Será obrigatória a troca no primeiro acesso." />
            </div>
        </section>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Provisionar clínica</button></div>
</form>
@endsection
