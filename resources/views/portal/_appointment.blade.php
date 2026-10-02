@php $tz = $ap->branch->timezone ?: 'America/Sao_Paulo'; $local = $ap->starts_at->timezone($tz); @endphp
<li>
    <div>
        <strong>{{ $local->translatedFormat('D, d/m/Y') }} às {{ $local->format('H:i') }}</strong>
        <span class="badge {{ ['confirmed' => 'badge-success', 'cancelled' => '', 'no_show' => 'badge-danger', 'completed' => 'badge-info'][$ap->status] ?? 'badge-warning' }}">{{ $ap->statusLabel() }}</span>
        <div class="small">{{ $ap->doctor->displayName() }} · {{ $ap->service?->name ?? 'Consulta' }} · {{ $ap->payer_type === 'insurance' ? 'Convênio' : 'Particular' }}</div>
        <div class="small muted">{{ $ap->branch->name }} — {{ $ap->branch->fullAddress() }} · protocolo {{ $ap->protocol }}</div>
    </div>
    @if (($actions ?? false) && in_array($ap->status, ['scheduled', 'confirmed'], true) && $ap->starts_at->isFuture())
        <div class="row">
            @if ($ap->status === 'scheduled')<form method="post" action="{{ route('portal.appointments.confirm', $ap) }}">@csrf<button class="btn btn-sm btn-primary" type="submit">Confirmar presença</button></form>@endif
            @if ($ap->starts_at->gt(now()->addHours($cancelHours ?? 24)))
                <form method="post" action="{{ route('portal.appointments.cancel', $ap) }}" data-confirm="Cancelar a consulta de {{ $local->format('d/m H:i') }}?">@csrf<button class="btn btn-sm btn-ghost" type="submit">Cancelar</button></form>
            @else
                <span class="small muted">Para cancelar, ligue para a clínica.</span>
            @endif
        </div>
    @endif
</li>
