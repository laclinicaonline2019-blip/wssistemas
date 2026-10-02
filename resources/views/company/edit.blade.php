@extends('layouts.app', ['title' => 'Configurações da clínica'])

@php $editable = auth()->user()->hasPermission('empresa.editar'); @endphp

@section('content')
<div class="page-head">
    <div><h1>Configurações da clínica</h1><p>{{ $company->legal_name }} · CNPJ {{ preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $company->document) }}</p></div>
</div>

<form method="post" action="{{ route('company.update') }}">
    @csrf @method('put')
    <div class="grid grid-2">
        <section class="card">
            <div class="card__head"><h2>Identificação</h2></div>
            <div class="card__body form-grid">
                <x-field name="trade_name" label="Nome fantasia" :value="$company->trade_name" col="col-12" :disabled="! $editable" />
                <x-field name="email" label="E-mail" type="email" :value="$company->email" col="col-6" :disabled="! $editable" />
                <x-field name="phone" label="Telefone" :value="$company->phone" col="col-6" mask="phone" :disabled="! $editable" />
            </div>
        </section>

        <section class="card">
            <div class="card__head"><h2>Segurança</h2></div>
            <div class="card__body stack">
                <input type="hidden" name="settings[security][require_2fa]" value="0">
                <label class="check">
                    <input type="checkbox" name="settings[security][require_2fa]" value="1" @checked($company->setting('security.require_2fa')) @disabled(! $editable)>
                    <span><strong>Exigir 2FA de todos os usuários</strong><br><span class="small muted">Usuários sem 2FA serão direcionados à configuração no próximo acesso.</span></span>
                </label>
            </div>
        </section>

        <section class="card">
            <div class="card__head"><h2>Impressão</h2>
                @if (auth()->user()->hasPermission('impressao.configurar'))
                    <div class="row">
                        <a class="btn btn-sm" href="{{ route('print.test', 'a4') }}" target="_blank" rel="noopener"><svg><use href="#i-printer"/></svg>Teste A4</a>
                        <a class="btn btn-sm" href="{{ route('print.test', 'thermal') }}" target="_blank" rel="noopener"><svg><use href="#i-printer"/></svg>Teste térmica</a>
                    </div>
                @endif
            </div>
            <div class="card__body form-grid">
                <x-field name="settings[print][header_text]" label="Texto do cabeçalho (receitas, atestados, recibos)" :value="$company->setting('print.header_text')" col="col-12" :disabled="! $editable" />
                <x-field name="settings[print][footer_text]" label="Texto do rodapé" :value="$company->setting('print.footer_text')" col="col-12" :disabled="! $editable" />
                <div class="field col-6">
                    <label for="thermal">Impressora térmica (senhas/comprovantes)</label>
                    <select id="thermal" name="settings[print][thermal_width_mm]" class="input" @disabled(! $editable)>
                        @foreach ([80 => '80 mm', 58 => '58 mm'] as $w => $l)
                            <option value="{{ $w }}" @selected((int) $company->setting('print.thermal_width_mm', 80) === $w)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card__head"><h2>Portal do paciente</h2>
                @if ($company->setting('portal.enabled', true))<a class="btn btn-sm" href="{{ route('portal.login', ['clinic' => $company->slug]) }}" target="_blank" rel="noopener">Abrir portal</a>@endif</div>
            <div class="card__body form-grid">
                <p class="help col-12">Endereço para divulgar aos pacientes: <span class="mono">{{ route('portal.login', ['clinic' => $company->slug]) }}</span></p>
                <input type="hidden" name="settings[portal][enabled]" value="0">
                <label class="check col-12"><input type="checkbox" name="settings[portal][enabled]" value="1" @checked($company->setting('portal.enabled', true)) @disabled(! $editable)><span>Portal do paciente ativo</span></label>
                <input type="hidden" name="settings[portal][booking_enabled]" value="0">
                <label class="check col-12"><input type="checkbox" name="settings[portal][booking_enabled]" value="1" @checked($company->setting('portal.booking_enabled', true)) @disabled(! $editable)><span>Permitir que o paciente agende pelo portal</span></label>
                <x-field name="settings[portal][booking_min_notice_hours]" label="Antecedência mínima para agendar (horas)" type="number" min="0" max="168" col="col-4" :value="$company->setting('portal.booking_min_notice_hours', 2)" :disabled="! $editable" />
                <x-field name="settings[portal][booking_max_days]" label="Agendar até quantos dias à frente" type="number" min="1" max="365" col="col-4" :value="$company->setting('portal.booking_max_days', 60)" :disabled="! $editable" />
                <x-field name="settings[portal][cancel_min_hours]" label="Cancelar até quantas horas antes" type="number" min="0" max="168" col="col-4" :value="$company->setting('portal.cancel_min_hours', 24)" :disabled="! $editable" />
            </div>
        </section>

        <section class="card">
            <div class="card__head"><h2>Plano</h2><span class="badge badge-primary">{{ $company->plan?->name ?? 'Sem plano' }}</span></div>
            <div class="card__body stack">
                @foreach (['max_users' => 'Usuários', 'max_branches' => 'Filiais'] as $k => $label)
                    @php $u = $usage[$k]; @endphp
                    <div>
                        <div class="spread small"><span>{{ $label }}</span><span>{{ $u['used'] }} / {{ $u['limit'] ?? 'ilimitado' }}</span></div>
                        @if ($u['limit'])<div class="meter"><span data-pct="{{ min(100, round($u['used'] / $u['limit'] * 100)) }}"></span></div>@endif
                    </div>
                @endforeach
                <p class="small muted">Status da assinatura: {{ ['trial' => 'Período de teste', 'active' => 'Ativa', 'suspended' => 'Suspensa', 'cancelled' => 'Cancelada'][$company->status] }}</p>
            </div>
        </section>
    </div>

    @if ($editable)
        <div class="form-actions"><button class="btn btn-primary" type="submit">Salvar configurações</button></div>
    @endif
</form>
@endsection
