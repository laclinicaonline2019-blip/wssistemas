@extends('layouts.app', ['title' => 'Convênios'])

@php use App\Core\Support\Format; $me = auth()->user(); @endphp

@section('content')
@include('insurance._tabs')
<div class="page-head">
    <div><h1>Convênios</h1><p>Operadoras, planos, médicos credenciados e tabelas de valores. O faturamento segue o padrão TISS da ANS.</p></div>
</div>

<section class="card mb-2">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Convênio</th><th>Registro ANS</th><th class="t-right">Planos</th><th class="t-right">Guias em aberto</th><th class="t-right">Prontas</th><th class="t-right">Faturado a receber</th><th class="t-right">Glosas</th><th></th></tr></thead>
        <tbody>
        @forelse ($insurers as $ins)
            @php $rows = $open[$ins->id] ?? collect(); $rec = $receivable[$ins->id] ?? null; @endphp
            <tr class="{{ $ins->is_active ? '' : 'muted' }}">
                <td><a href="{{ route('insurers.show', $ins) }}"><strong>{{ $ins->name }}</strong></a> @unless ($ins->is_active)<span class="badge">inativo</span>@endunless
                    @if ($ins->tissIssues())<div class="small text-danger">Falta: {{ implode(', ', $ins->tissIssues()) }}</div>@endif</td>
                <td class="mono">{{ $ins->ans_registry ?? '—' }}</td>
                <td class="t-right">{{ $ins->plans_count }}</td>
                <td class="t-right">{{ (int) $rows->where('status', 'draft')->sum('qty') }}</td>
                <td class="t-right">{{ (int) $rows->where('status', 'ready')->sum('qty') }}</td>
                <td class="t-right">{{ Format::money((int) ($rec?->due ?? 0)) }}</td>
                <td class="t-right">{{ Format::money((int) ($rec?->glosa ?? 0)) }}</td>
                <td class="actions"><a class="btn btn-sm" href="{{ route('insurers.show', $ins) }}">Abrir</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="empty">Nenhum convênio cadastrado.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>

@if ($me->hasPermission('convenio.gerenciar'))
<section class="card">
    <div class="card__head"><h2>Novo convênio</h2></div>
    <form method="post" action="{{ route('insurers.store') }}" class="card__body form-grid">
        @csrf
        @include('insurance._insurer-fields', ['insurer' => null])
        <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Cadastrar convênio</button></div>
    </form>
</section>
@endif
@endsection
