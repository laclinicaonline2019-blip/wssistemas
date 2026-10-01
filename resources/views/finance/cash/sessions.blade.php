@extends('layouts.app', ['title' => 'Conferência de caixa'])

@php use App\Core\Support\Format; $tz = 'America/Sao_Paulo'; @endphp

@section('content')
@include('finance._tabs')
<div class="page-head">
    <div><h1>Conferência de caixa</h1><p>Caixas fechados aguardam conferência por outra pessoa. Diferenças exigem justificativa.</p></div>
    <form method="get"><label class="sr-only" for="ss">Situação</label>
        <select id="ss" name="status" class="input w-auto" data-autosubmit>
            @foreach (['closed' => 'Aguardando conferência', 'open' => 'Abertos', 'reviewed' => 'Conferidos', 'all' => 'Todos'] as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach
        </select></form>
</div>

<section class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Abertura</th><th>Operador</th><th class="hide-sm">Unidade</th><th>Fechamento</th><th class="t-right">Diferença</th><th>Situação</th><th></th></tr></thead>
        <tbody>
        @forelse ($sessions as $s)
            <tr>
                <td class="small nowrap">{{ $s->opened_at->timezone($tz)->format('d/m/Y H:i') }}</td>
                <td>{{ $s->operator->name }}</td>
                <td class="hide-sm small">{{ $s->branch->name }}</td>
                <td class="small nowrap">{{ $s->closed_at?->timezone($tz)->format('d/m/Y H:i') ?? '—' }}</td>
                <td class="t-right nowrap">@if ($s->difference_cents === null)—@else<span class="{{ $s->difference_cents ? 'text-danger' : 'text-success' }}">{{ Format::money($s->difference_cents) }}</span>@endif</td>
                <td><span class="badge {{ ['open' => 'badge-info', 'closed' => 'badge-warning', 'reviewed' => 'badge-success'][$s->status] }}">{{ ['open' => 'aberto', 'closed' => 'a conferir', 'reviewed' => 'conferido'][$s->status] }}</span></td>
                <td class="actions"><a class="btn btn-sm" href="{{ route('cash.show', $s) }}">Abrir</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">Nenhum caixa nesta situação.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $sessions->links() }}
</section>
@endsection
