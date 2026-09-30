@extends('layouts.app', ['title' => 'Planos SaaS'])

@php
    $fields = function ($p = null) {
        return [
            ['max_users', 'Máx. usuários', $p?->limit('max_users')],
            ['max_branches', 'Máx. filiais', $p?->limit('max_branches')],
            ['max_doctors', 'Máx. médicos', $p?->limit('max_doctors')],
            ['storage_mb', 'Armazenamento (MB)', $p?->limit('storage_mb')],
        ];
    };
@endphp

@section('content')
<div class="page-head"><div><h1>Planos SaaS</h1><p>Preços, limites e recursos por plano. Campos de limite vazios = ilimitado.</p></div></div>

<div class="grid grid-3">
    @foreach ($plans as $p)
        <form method="post" action="{{ route('platform.plans.update', $p) }}" class="card">
            @csrf @method('put')
            <div class="card__head"><h2>{{ $p->name }}</h2><span class="badge">{{ $p->companies_count }} clínicas</span></div>
            <div class="card__body form-grid">
                <input type="hidden" name="code" value="{{ $p->code }}">
                <div class="field col-12"><label>Nome</label><input name="name" class="input" value="{{ $p->name }}" required></div>
                <div class="field col-6"><label>Mensal (centavos)</label><input name="price_monthly_cents" type="number" min="0" class="input" value="{{ $p->price_monthly_cents }}" required></div>
                <div class="field col-6"><label>Anual (centavos)</label><input name="price_yearly_cents" type="number" min="0" class="input" value="{{ $p->price_yearly_cents }}" required></div>
                <div class="field col-6"><label>Dias de trial</label><input name="trial_days" type="number" min="0" max="90" class="input" value="{{ $p->trial_days }}"></div>
                @foreach ($fields($p) as [$k, $l, $v])
                    <div class="field col-6"><label>{{ $l }}</label><input name="limits[{{ $k }}]" type="number" min="0" class="input" value="{{ $v }}"></div>
                @endforeach
                <label class="check col-6"><input type="checkbox" name="limits[ai_enabled]" value="1" @checked($p->limit('ai_enabled'))><span>IA</span></label>
                <label class="check col-6"><input type="checkbox" name="limits[whatsapp_enabled]" value="1" @checked($p->limit('whatsapp_enabled'))><span>WhatsApp</span></label>
                <label class="check col-12"><input type="checkbox" name="is_active" value="1" @checked($p->is_active)><span>Disponível para novas clínicas</span></label>
                <div class="col-12 form-actions"><button class="btn btn-primary btn-sm" type="submit">Salvar</button></div>
            </div>
        </form>
    @endforeach

    <form method="post" action="{{ route('platform.plans.store') }}" class="card">
        @csrf
        <div class="card__head"><h2>Novo plano</h2></div>
        <div class="card__body form-grid">
            <div class="field col-6"><label>Código</label><input name="code" class="input" required pattern="[a-z0-9_\-]+" value="{{ old('code') }}"></div>
            <div class="field col-6"><label>Nome</label><input name="name" class="input" required value="{{ old('name') }}"></div>
            <div class="field col-6"><label>Mensal (centavos)</label><input name="price_monthly_cents" type="number" min="0" class="input" required value="{{ old('price_monthly_cents', 0) }}"></div>
            <div class="field col-6"><label>Anual (centavos)</label><input name="price_yearly_cents" type="number" min="0" class="input" required value="{{ old('price_yearly_cents', 0) }}"></div>
            @foreach ($fields() as [$k, $l, $v])
                <div class="field col-6"><label>{{ $l }}</label><input name="limits[{{ $k }}]" type="number" min="0" class="input"></div>
            @endforeach
            <label class="check col-6"><input type="checkbox" name="limits[ai_enabled]" value="1"><span>IA</span></label>
            <label class="check col-6"><input type="checkbox" name="limits[whatsapp_enabled]" value="1"><span>WhatsApp</span></label>
            <label class="check col-12"><input type="checkbox" name="is_active" value="1" checked><span>Disponível</span></label>
            <div class="col-12 form-actions"><button class="btn btn-primary btn-sm" type="submit">Criar plano</button></div>
        </div>
    </form>
</div>
@endsection
