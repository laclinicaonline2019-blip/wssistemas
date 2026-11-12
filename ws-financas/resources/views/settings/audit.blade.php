@extends('layouts.app')
@section('title', 'Auditoria')
@section('content')
<div class="blockhead"><h2>Auditoria</h2><span class="small muted">Registro imutável de tudo que foi feito na sua conta</span></div>
<div class="table-wrap"><table class="t"><thead><tr><th>Quando</th><th>Quem</th><th>Ação</th><th>Detalhes</th><th>Canal</th></tr></thead><tbody>
@foreach ($logs as $l)
  <tr><td class="small">{{ $l->created_at->format('d/m/Y H:i') }}</td><td class="small">{{ $users[$l->user_id] ?? ($l->channel === 'whatsapp' ? 'WhatsApp' : 'sistema') }}</td><td>{{ str_replace(['.','_'],[' › ',' '],$l->action) }}</td>
    <td class="small muted" style="max-width:360px;overflow-wrap:anywhere">{{ $l->data ? \Illuminate\Support\Str::limit(json_encode($l->data, JSON_UNESCAPED_UNICODE), 220) : '' }}</td><td class="small">{{ $l->channel }}</td></tr>
@endforeach
</tbody></table></div>
{{ $logs->links('partials.pager') }}
@endsection
