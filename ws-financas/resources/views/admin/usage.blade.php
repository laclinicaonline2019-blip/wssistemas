@extends('layouts.admin')
@section('title', 'Uso de WhatsApp e IA')
@section('content')
<form class="tools" method="get"><input type="month" name="mes" value="{{ $period }}" style="border:1px solid var(--line);border-radius:10px;padding:8px 12px;background:var(--surface)"><button class="btn" type="submit">Ver</button></form>
<div class="table-wrap"><table class="t"><thead><tr><th>Cliente</th><th>Plano</th><th class="n">WhatsApp recebidas</th><th class="n">Enviadas</th><th class="n">Limite</th><th class="n">IA</th><th class="n">Tokens (entrada/saída)</th></tr></thead><tbody>
@forelse ($rows as $r) @php($a = $accounts[$r->account_id] ?? null)
<tr><td>{{ $a?->name }}</td><td>{{ $a?->subscription?->plan->name }}</td><td class="n">{{ $r->whatsapp_in }}</td><td class="n">{{ $r->whatsapp_out }}</td><td class="n">{{ $a?->subscription?->plan->whatsapp_messages_month }}</td><td class="n">{{ $r->ai_calls }}</td><td class="n">{{ number_format($r->ai_input_tokens,0,',','.') }} / {{ number_format($r->ai_output_tokens,0,',','.') }}</td></tr>
@empty<tr><td colspan="7" class="muted">Sem uso no período.</td></tr>@endforelse
</tbody></table></div>
@endsection
