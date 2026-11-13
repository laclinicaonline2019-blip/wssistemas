@extends('layouts.admin')
@section('title', 'Atualização em andamento')
@section('content')
<div class="card" id="update" data-step-url="{{ route('admin.updates.step', $run['id']) }}" data-status="{{ $run['status'] }}">
  <div class="blockhead"><h2>Atualização {{ $run['from'] }} → {{ $run['to'] }}</h2><span id="up-status" class="pill warn">{{ $run['status'] }}</span></div>
  <p class="small muted mt0">Não feche esta página até terminar. Se a conexão cair, abra de novo: a atualização continua de onde parou.</p>
  <ol class="list order" style="padding:0" id="up-steps">
    @foreach ($steps as $k => $label)
      <li data-step="{{ $k }}" style="grid-template-columns:28px minmax(0,1fr) auto"><span class="step">{{ $loop->iteration }}</span><span>{{ $label }}</span><span class="small muted" data-mark>{{ in_array($k, $run['done']) ? '✓' : '' }}</span></li>
    @endforeach
  </ol>
  <div id="up-msg" class="banner info" hidden></div>
  <pre id="up-log" class="small" style="white-space:pre-wrap;background:var(--sunk);padding:10px;border-radius:10px;max-height:260px;overflow:auto">@foreach ($run['log'] as $l){{ $l['at'] }} {{ $l['ok'] ? '✓' : '✗' }} {{ $steps[$l['step']] ?? $l['step'] }} — {{ $l['msg'] }}
@endforeach</pre>
  <div class="formacts"><a class="btn" href="{{ route('admin.updates') }}">Voltar para Atualizações</a></div>
</div>
@endsection
@push('scripts')<script src="{{ asset('assets/js/update.js') }}?v={{ filemtime(public_path('assets/js/update.js')) }}"></script>@endpush
