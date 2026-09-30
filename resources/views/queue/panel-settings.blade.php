@extends('layouts.app', ['title' => 'Painel de chamadas'])

@php $p = $branch->settings['panel'] ?? []; @endphp

@section('content')
<div class="page-head">
    <div><h1>Painel de chamadas — {{ $branch->name }}</h1><p>Tela para TV/monitor na sala de espera.</p></div>
    <form method="get" class="row"><label class="sr-only" for="pb">Unidade</label>
        <select id="pb" name="branch_id" class="input w-auto" data-autosubmit>@foreach ($branches as $b)<option value="{{ $b->id }}" @selected($b->id === $branch->id)>{{ $b->name }}</option>@endforeach</select></form>
</div>

<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Endereço do painel</h2></div>
        <div class="card__body stack">
            @if (! empty($p['token']))
                <p class="text-2">Abra este endereço no navegador da TV (tela cheia com F11). Não é necessário login.</p>
                <div class="codes" id="panel-url">{{ route('panel.show', $p['token']) }}</div>
                <div class="row"><button type="button" class="btn btn-sm" data-copy="#panel-url">Copiar endereço</button>
                    <a class="btn btn-sm" href="{{ route('panel.show', $p['token']) }}" target="_blank" rel="noopener">Abrir painel</a></div>
                <p class="help">Trate o endereço como uma senha. Se vazar, gere um novo (o antigo deixa de funcionar).</p>
            @else
                <p class="text-2">Salve as configurações para gerar o endereço do painel.</p>
            @endif
        </div>
    </section>

    <form method="post" action="{{ route('queue.panel.update') }}" class="card">
        @csrf @method('put')
        <input type="hidden" name="branch_id" value="{{ $branch->id }}">
        <div class="card__head"><h2>Configurações</h2></div>
        <div class="card__body form-grid">
            <div class="field col-12"><label for="show_name">Identificação do paciente no painel</label>
                <select id="show_name" name="show_name" class="input">
                    <option value="short" @selected(($p['show_name'] ?? 'short') === 'short')>Primeiro nome + inicial (ex.: Maria S.)</option>
                    <option value="none" @selected(($p['show_name'] ?? 'short') === 'none')>Somente a senha (máxima privacidade)</option>
                </select></div>
            <label class="check col-6"><input type="checkbox" name="sound" value="1" @checked($p['sound'] ?? true)><span>Sinal sonoro</span></label>
            <label class="check col-6"><input type="checkbox" name="voice" value="1" @checked($p['voice'] ?? true)><span>Voz ("Senha A025, sala 3")</span></label>
            <div class="field col-6"><label for="repeat">Repetições da chamada</label>
                <select id="repeat" name="repeat" class="input">@foreach ([1, 2, 3] as $n)<option @selected((int) ($p['repeat'] ?? 2) === $n)>{{ $n }}</option>@endforeach</select></div>
            <div class="field col-6"><label for="volume">Volume</label>
                <select id="volume" name="volume" class="input">@foreach (['0.4' => 'Baixo', '0.7' => 'Médio', '1' => 'Alto'] as $v => $l)<option value="{{ $v }}" @selected((string) ($p['volume'] ?? '1') === $v)>{{ $l }}</option>@endforeach</select></div>
            @if (! empty($p['token']))
                <label class="check col-12"><input type="checkbox" name="rotate_token" value="1"><span>Gerar novo endereço (invalida o atual)</span></label>
            @endif
            <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Salvar</button></div>
        </div>
    </form>
</div>
@endsection
