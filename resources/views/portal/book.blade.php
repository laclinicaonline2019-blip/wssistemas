@extends('portal.layout', ['title' => 'Agendar'])

@section('content')
<div class="page-head"><div><h1>Agendar consulta</h1>
    <p>Horários livres a partir de {{ $settings['min_notice_hours'] }} horas, até {{ $settings['max_days_ahead'] }} dias à frente.</p></div></div>

@if (! $settings['booking'])
    <div class="alert alert-info">O agendamento online está desativado. Fale com a clínica.</div>
@else
<section class="card mb-2">
    <div class="card__head"><h2>1. Escolha o médico</h2></div>
    <ul class="portal-list">
        @forelse ($doctors as $d)
            <li><span><strong>{{ $d->displayName() }}</strong><br><span class="small muted">{{ $d->specialties->pluck('name')->implode(', ') }} · {{ $d->branches->pluck('name')->implode(', ') }}</span></span>
                <a class="btn btn-sm {{ $doctor?->id === $d->id ? 'btn-primary' : '' }}" href="{{ route('portal.book', ['doctor_id' => $d->id]) }}">{{ $doctor?->id === $d->id ? 'Selecionado' : 'Ver horários' }}</a></li>
        @empty<li class="muted">Nenhum médico com agenda online.</li>@endforelse
    </ul>
</section>

@if ($doctor)
<section class="card">
    <div class="card__head"><h2>2. Horário — {{ $doctor->displayName() }}</h2>
        @if ($branches->count() > 1)
            <form method="get" class="row"><input type="hidden" name="doctor_id" value="{{ $doctor->id }}">
                <label class="sr-only" for="bk-b">Unidade</label><select id="bk-b" name="branch_id" class="input w-auto">@foreach ($branches as $b)<option value="{{ $b->id }}" @selected($branch?->id === $b->id)>{{ $b->name }}</option>@endforeach</select>
                <button class="btn btn-sm" type="submit">Trocar unidade</button></form>
        @endif
    </div>
    @if (! $branch || $slots === [])
        <p class="card__body muted">Sem horários livres no período. Tente outra unidade ou fale com a clínica.</p>
    @else
        <form method="post" action="{{ route('portal.book.store') }}" class="card__body form-grid">
            @csrf
            <input type="hidden" name="doctor_id" value="{{ $doctor->id }}"><input type="hidden" name="branch_id" value="{{ $branch->id }}">
            <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::random(24) }}">
            <div class="field col-6"><label for="bk-s">Tipo de atendimento</label>
                <select id="bk-s" name="service_id" class="input" required>@foreach ($doctor->services as $s)<option value="{{ $s->id }}">{{ $s->name }}{{ $s->accepts_private && $s->price_cents > 0 ? ' — particular '.$s->priceFormatted() : '' }}</option>@endforeach</select></div>
            <div class="field col-6"><label for="bk-p">Pagamento</label>
                <select id="bk-p" name="payer_type" class="input"><option value="private">Particular</option>@if ($insurances->isNotEmpty())<option value="insurance">Convênio</option>@endif</select></div>
            @if ($insurances->isNotEmpty())
                <div class="field col-12"><label for="bk-i">Carteirinha (se convênio)</label>
                    <select id="bk-i" name="patient_insurance_id" class="input">@foreach ($insurances as $i)<option value="{{ $i->id }}">{{ $i->insurer_name }} · {{ $i->card_number }}</option>@endforeach</select></div>
            @endif
            <fieldset class="col-12 form-grid"><legend class="label">Horários livres ({{ $branch->name }})</legend>
                <div class="slot-grid col-12">
                    @foreach ($slots as $i => $slot)
                        @php $local = $slot->start->setTimezone($branch->timezone ?: 'America/Sao_Paulo'); @endphp
                        <label class="check btn"><input type="radio" name="starts_at" value="{{ $slot->start->toIso8601String() }}" @checked($i === 0) required><span>{{ $local->translatedFormat('D d/m') }} {{ $local->format('H:i') }}</span></label>
                    @endforeach
                </div>
            </fieldset>
            <div class="col-12 form-actions"><button class="btn btn-primary" type="submit">Confirmar agendamento</button></div>
        </form>
    @endif
</section>
@endif
@endif
@endsection
