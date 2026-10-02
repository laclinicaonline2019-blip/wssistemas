@extends('portal.layout', ['title' => 'Médicos'])

@section('content')
<div class="page-head"><div><h1>Corpo clínico</h1></div></div>
<div class="grid grid-2">
    @foreach ($doctors as $d)
        <section class="card"><div class="card__body stack">
            <strong>{{ $d->displayName() }}</strong>
            <span class="small muted">{{ $d->registration() }} · {{ $d->specialties->pluck('name')->implode(', ') }}</span>
            <span class="small">Atende em: {{ $d->branches->pluck('name')->implode(', ') }}</span>
            @if ($d->bio)<p class="small">{{ $d->bio }}</p>@endif
            @if ($company->setting('portal.booking_enabled', true))<a class="btn btn-sm" href="{{ route('portal.book', ['doctor_id' => $d->id]) }}">Ver horários</a>@endif
        </div></section>
    @endforeach
</div>
@endsection
