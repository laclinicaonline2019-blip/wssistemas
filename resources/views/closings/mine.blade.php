@extends('layouts.app', ['title' => 'Meus fechamentos'])

@php use App\Core\Support\Format; @endphp

@section('content')
<div class="page-head"><div><h1>Meus fechamentos</h1><p>Demonstrativos mensais enviados pela clínica para sua conferência.</p></div></div>
<section class="card">
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Mês</th><th>Situação</th><th class="num">Sua parte</th><th class="num">Repasse a receber</th><th></th></tr></thead>
        <tbody>
        @forelse ($closings as $c)
            <tr><td>{{ $c->periodLabel() }}</td><td><span class="badge {{ ['confirmed' => 'badge-success', 'disputed' => 'badge-danger'][$c->status] ?? 'badge-warning' }}">{{ $c->statusLabel() }}</span></td>
                <td class="num">{{ Format::money($c->doctor_share_cents) }}</td><td class="num">{{ Format::money($c->to_pay_cents) }}</td>
                <td class="actions"><a class="btn btn-sm" href="{{ route('closings.show', $c) }}">{{ $c->status === 'closed' ? 'Conferir' : 'Ver' }}</a></td></tr>
        @empty
            <tr><td colspan="5" class="empty">Nenhum demonstrativo ainda.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</section>
@endsection
