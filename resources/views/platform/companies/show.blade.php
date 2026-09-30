@extends('layouts.app', ['title' => $company->trade_name])

@section('content')
<div class="page-head">
    <div><h1>{{ $company->trade_name }}</h1><p>{{ $company->legal_name }} · CNPJ {{ $company->document }} · @include('platform._status', ['status' => $company->status])</p></div>
    <a class="btn" href="{{ route('platform.companies.index') }}">Voltar</a>
</div>

<div class="grid grid-3">
    <div class="card kpi"><div class="kpi__label">Usuários</div><div class="kpi__value">{{ $stats['users'] }}</div><div class="kpi__hint">limite {{ $company->plan?->limit('max_users') ?? '∞' }}</div></div>
    <div class="card kpi"><div class="kpi__label">Unidades</div><div class="kpi__value">{{ $stats['branches'] }}</div><div class="kpi__hint">limite {{ $company->plan?->limit('max_branches') ?? '∞' }}</div></div>
    <div class="card kpi"><div class="kpi__label">Trial até</div><div class="kpi__value">{{ $company->trial_ends_at?->format('d/m/Y') ?? '—' }}</div><div class="kpi__hint">criada em {{ $company->created_at->format('d/m/Y') }}</div></div>
</div>

<div class="grid grid-2 mt-2">
    <form method="post" action="{{ route('platform.companies.update', $company) }}" class="card" data-confirm="Confirmar alteração da assinatura? Suspender/cancelar encerra as sessões da clínica.">
        @csrf @method('put')
        <div class="card__head"><h2>Assinatura</h2></div>
        <div class="card__body form-grid">
            <div class="field col-6"><label for="st">Status</label>
                <select id="st" name="status" class="input">
                    @foreach (['trial' => 'Trial', 'active' => 'Ativa', 'suspended' => 'Suspensa (inadimplência)', 'cancelled' => 'Cancelada'] as $k => $l)
                        <option value="{{ $k }}" @selected($company->status === $k)>{{ $l }}</option>@endforeach
                </select></div>
            <div class="field col-6"><label for="pl">Plano</label>
                <select id="pl" name="saas_plan_id" class="input"><option value="">Sem plano</option>
                    @foreach ($plans as $p)<option value="{{ $p->id }}" @selected($company->saas_plan_id === $p->id)>{{ $p->name }}</option>@endforeach
                </select></div>
            <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Salvar</button></div>
        </div>
    </form>
    <section class="card">
        <div class="card__head"><h2>Eventos administrativos</h2></div>
        <div class="table-wrap"><table class="table"><tbody>
            @forelse ($events as $log)
                <tr><td>@include('partials.audit-action', ['log' => $log])</td><td class="small muted nowrap">{{ $log->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</td></tr>
            @empty
                <tr><td class="empty">Sem eventos.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</div>
@endsection
