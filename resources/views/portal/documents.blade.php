@extends('portal.layout', ['title' => 'Documentos'])

@section('content')
<div class="page-head"><div><h1>Documentos e exames</h1><p>Receitas, atestados, pedidos de exames e documentos emitidos para você, e arquivos liberados pela clínica.</p></div></div>
<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Emitidos pelos médicos</h2></div>
        <ul class="portal-list">
            @forelse ($documents as $d)
                <li><span><strong>{{ $d->typeLabel() }}</strong><br><span class="small muted">{{ $d->issued_at->timezone('America/Sao_Paulo')->format('d/m/Y') }} · {{ $d->doctor?->displayName() }} · código {{ $d->verification_code }}</span></span>
                    <a class="btn btn-sm" href="{{ route('portal.documents.pdf', $d) }}">Baixar PDF</a></li>
            @empty<li class="muted">Nenhum documento.</li>@endforelse
        </ul>
        @include('partials.pagination', ['paginator' => $documents])
        <p class="card__body small muted">A autenticidade de cada documento pode ser conferida pelo QR Code impresso nele.</p>
    </section>
    <section class="card">
        <div class="card__head"><h2>Exames e arquivos</h2></div>
        <ul class="portal-list">
            @forelse ($files as $f)
                <li><span><strong>{{ $f->title }}</strong><br><span class="small muted">{{ $f->created_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}</span></span>
                    <a class="btn btn-sm" href="{{ route('portal.files.download', $f) }}">Baixar</a></li>
            @empty<li class="muted">Nenhum arquivo liberado pela clínica.</li>@endforelse
        </ul>
    </section>
</div>
@endsection
