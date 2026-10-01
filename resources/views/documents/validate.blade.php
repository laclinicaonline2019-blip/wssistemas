@extends('layouts.guest', ['title' => 'Validar documento'])

@section('content')
    <h1>Validar documento</h1>
    <p class="muted">Confira a autenticidade de receitas, atestados e demais documentos emitidos pelo sistema.</p>

    <form method="get" action="{{ route('documents.validate.lookup') }}" class="row mt-1">
        <label class="sr-only" for="code">Código de verificação</label>
        <input id="code" name="code" class="input" placeholder="XXXX-XXXX-XXXX" maxlength="20" required value="{{ $doc['code'] ?? '' }}" autocomplete="off">
        <button class="btn btn-brand" type="submit">Validar</button>
    </form>

    @if ($searched && ! $doc)
        <div class="alert alert-error mt-2">Documento não encontrado. Confira o código impresso no rodapé.</div>
    @elseif ($doc)
        @if (! $doc['intact'])
            <div class="alert alert-error mt-2"><strong>ATENÇÃO:</strong> o registro deste documento não confere com o selo de integridade. Não aceite o documento e contate a clínica.</div>
        @elseif ($doc['status'] === 'cancelled')
            <div class="alert alert-error mt-2"><strong>DOCUMENTO CANCELADO</strong> pelo emitente em {{ $doc['cancelled_at'] }}. Não tem validade.</div>
        @else
            <div class="alert alert-success mt-2"><strong>Documento autêntico e válido</strong>, emitido pelo sistema.</div>
        @endif
        <dl class="dl mt-1">
            <dt>Documento</dt><dd>{{ $doc['type'] }} nº {{ $doc['number'] }}</dd>
            <dt>Emissão</dt><dd>{{ $doc['issued_at'] }}</dd>
            @if ($doc['valid_until'])<dt>Validade</dt><dd>até {{ $doc['valid_until'] }}</dd>@endif
            <dt>Clínica</dt><dd>{{ $doc['clinic'] }}</dd>
            <dt>Médico</dt><dd>{{ $doc['doctor'] }}</dd>
            <dt>Paciente</dt><dd>{{ $doc['patient'] }} <span class="small muted">(iniciais)</span></dd>
            @if ($doc['days'])<dt>Afastamento</dt><dd>{{ $doc['days'] }} dia(s)</dd>@endif
            @if ($doc['items'])<dt>Prescrição</dt><dd>@foreach ($doc['items'] as $i)<div>{{ $i }}</div>@endforeach</dd>@endif
        </dl>
        <p class="small muted mt-2">Confira se os dados acima correspondem ao papel apresentado.</p>
    @endif
@endsection
