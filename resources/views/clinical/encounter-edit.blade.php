@extends('layouts.app', ['title' => ($addendum ?? false) ? 'Adendo ao atendimento' : 'Atendimento'])

@php
    use App\Modules\Clinical\Models\Encounter;
    $me = auth()->user();
    $tz = 'America/Sao_Paulo';
    $patient = $encounter->patient;
    $isAddendum = $addendum ?? false;
    $content = $isAddendum ? ($encounter->latestVersion->data ?? []) + ['diagnoses' => $encounter->latestVersion->diagnoses ?? []] : ($encounter->draft_data ?? []);
    $content = array_replace($content, array_filter((array) old('data', []), fn ($v) => $v !== null));
    $diagnoses = array_values($content['diagnoses'] ?? []);
    $tall = ['history', 'physical_exam', 'conduct'];
@endphp

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $patient->displayName() }}</h1>
        <p>Prontuário <span class="record-no">#{{ $patient->record_number }}</span>
            @if ($patient->age() !== null) · {{ $patient->age() }} anos @endif
            @if ($patient->sex) · {{ \App\Modules\Patients\Models\Patient::SEXES[$patient->sex] ?? '' }} @endif
            · {{ $encounter->branch->name }}
            @if ($encounter->appointment?->service) · {{ $encounter->appointment->service->name }} @endif
            @foreach ($patient->insurances->where('is_primary', true) as $ins) · <span class="badge">{{ $ins->insurer_name }}</span> @endforeach
        </p>
    </div>
    <div class="row">
        @unless ($isAddendum)<span class="small muted" data-save-status aria-live="polite">{{ $encounter->draft_saved_at ? 'Salvo às '.$encounter->draft_saved_at->timezone($tz)->format('H:i') : 'Rascunho' }}</span>@endunless
        <a class="btn" href="{{ $isAddendum ? route('encounters.show', $encounter) : route('workspace') }}">Voltar</a>
    </div>
</div>

@if ($allergies->isNotEmpty())
    <div class="alert alert-error" role="alert"><strong>ALERGIAS:</strong>
        @foreach ($allergies as $al){{ $al->substance }}{{ $al->reaction ? ' ('.$al->reaction.')' : '' }} — {{ \App\Modules\Clinical\Models\PatientAllergy::SEVERITIES[$al->severity] }}@if (! $loop->last); @endif @endforeach
    </div>
@endif

@if ($isAddendum)
    <div class="alert alert-info">O registro original (versão {{ $encounter->current_version }}) permanece intacto. O adendo cria uma nova versão completa, com a sua justificativa, data e hora.</div>
@endif

<div class="encounter-layout">
    <form method="post" id="encounter-form" class="card"
          action="{{ $isAddendum ? route('encounters.addendum.store', $encounter) : route('encounters.finalize', $encounter) }}"
          @unless ($isAddendum) data-encounter-editor data-autosave-url="{{ route('encounters.autosave', $encounter) }}" @endunless
          data-cid-url="{{ route('clinical.cid') }}" data-cid-favorite-url="{{ url('clinico/cid/__ID__/favorito') }}"
          data-confirm="{{ $isAddendum ? 'Registrar este adendo? Ele não poderá ser alterado depois.' : 'Finalizar o atendimento? Depois de finalizado, o registro não pode mais ser editado — apenas complementado por adendo.' }}">
        @csrf
        @unless ($isAddendum)<input type="hidden" name="revision" value="{{ $encounter->draft_revision }}" data-revision>@endunless
        <div class="card__body form-grid">
            @if ($isAddendum)
                <div class="field col-12">
                    <label for="f-reason">Justificativa do adendo <span aria-hidden="true">*</span></label>
                    <input id="f-reason" name="reason" class="input @error('reason') is-invalid @enderror" value="{{ old('reason') }}" minlength="10" maxlength="500" required
                           placeholder="Ex.: resultado de exame recebido após a consulta; correção de posologia">
                    @error('reason')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            @endif

            @if ($triage)
                <div class="col-12 small text-2"><strong>Triagem {{ $triage->created_at->timezone($tz)->format('H:i') }}:</strong> {{ $triage->summary() ?: 'sem sinais vitais' }}
                    @if ($triage->risk)<span class="badge risk-{{ $triage->risk }}">{{ \App\Modules\Clinical\Models\Triage::RISKS[$triage->risk] }}</span>@endif</div>
            @endif

            @foreach (Encounter::SECTIONS as $key => $label)
                <div class="field col-12">
                    <label for="f-{{ $key }}">{{ $label }}@if (in_array($key, ['chief_complaint', 'conduct'], true)) <span aria-hidden="true">*</span>@endif</label>
                    <textarea id="f-{{ $key }}" name="data[{{ $key }}]" class="input" rows="{{ in_array($key, $tall, true) ? 6 : ($key === 'chief_complaint' ? 2 : 3) }}" maxlength="20000">{{ $content[$key] ?? '' }}</textarea>
                </div>

                @if ($key === 'assessment')
                    <div class="field col-12" data-dx>
                        <span class="label">Diagnósticos (CID-10)</span>
                        <div class="lookup">
                            <label class="sr-only" for="cid-q">Buscar CID por código ou descrição</label>
                            <input id="cid-q" class="input" placeholder="Buscar CID por código (ex.: J06) ou descrição (ex.: faringite)" autocomplete="off" data-cid-search>
                            <div class="lookup__list hidden" data-cid-results></div>
                        </div>
                        @if ($shortcuts->isNotEmpty())
                            <div class="chips mt-1">
                                @foreach ($shortcuts as $s)
                                    <button type="button" class="btn btn-sm btn-ghost" data-cid-add data-id="{{ $s->id }}" data-code="{{ $s->code }}" data-description="{{ $s->description }}" title="{{ $s->description }}">{{ $s->code }}</button>
                                @endforeach
                            </div>
                        @endif
                        <div class="table-wrap mt-1"><table class="table">
                            <thead><tr><th>Principal</th><th>CID</th><th class="hide-sm">Observação</th><th></th></tr></thead>
                            <tbody data-dx-rows>
                            @foreach ($diagnoses as $i => $d)
                                <tr data-dx-row>
                                    <td><input type="radio" name="dx_primary" value="{{ $i }}" @checked($d['is_primary'] ?? false) aria-label="Diagnóstico principal"><input type="hidden" name="data[diagnoses][{{ $i }}][is_primary]" value="{{ ($d['is_primary'] ?? false) ? 1 : 0 }}" data-dx-primary></td>
                                    <td><input type="hidden" name="data[diagnoses][{{ $i }}][cid_code_id]" value="{{ $d['cid_code_id'] }}"><strong>{{ $d['code'] }}</strong> {{ $d['description'] }}</td>
                                    <td class="hide-sm"><input class="input input-sm" name="data[diagnoses][{{ $i }}][notes]" value="{{ $d['notes'] ?? '' }}" maxlength="255" aria-label="Observação"></td>
                                    <td class="actions"><button type="button" class="btn btn-sm btn-ghost" data-dx-remove aria-label="Remover diagnóstico">×</button></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table></div>
                        <p class="help" data-dx-empty @if ($diagnoses) hidden @endif>Nenhum diagnóstico informado. O primeiro adicionado será o principal.</p>
                        <template id="dx-template">
                            <tr data-dx-row>
                                <td><input type="radio" name="dx_primary" value="__I__" aria-label="Diagnóstico principal"><input type="hidden" name="data[diagnoses][__I__][is_primary]" value="0" data-dx-primary></td>
                                <td><input type="hidden" name="data[diagnoses][__I__][cid_code_id]" data-dx-id><strong data-dx-code></strong> <span data-dx-desc></span></td>
                                <td class="hide-sm"><input class="input input-sm" name="data[diagnoses][__I__][notes]" maxlength="255" aria-label="Observação"></td>
                                <td class="actions"><button type="button" class="btn btn-sm btn-ghost" data-dx-remove aria-label="Remover diagnóstico">×</button></td>
                            </tr>
                        </template>
                    </div>
                @endif
            @endforeach

            <div class="field col-4">
                <label for="f-return">Retorno em (dias)</label>
                <input id="f-return" type="number" min="1" max="365" name="data[return_in_days]" class="input" value="{{ $content['return_in_days'] ?? '' }}">
            </div>
        </div>
        <div class="card__body form-actions">
            @if ($isAddendum)
                <button class="btn btn-primary" type="submit">Registrar adendo</button>
            @else
                <span class="small muted">O rascunho é salvo automaticamente a cada poucos segundos.</span>
                <button class="btn" type="button" data-save-now>Salvar rascunho</button>
                @if ($me->hasPermission('prontuario.finalizar'))
                    <button class="btn btn-primary" type="submit">Finalizar atendimento</button>
                @endif
            @endif
        </div>
    </form>

    <aside class="stack">
        <section class="card">
            <div class="card__head"><h2>Alergias</h2></div>
            <div class="card__body stack">
                @forelse ($allergies as $al)
                    <div class="spread small"><span><strong>{{ $al->substance }}</strong>{{ $al->reaction ? ' — '.$al->reaction : '' }}</span><span class="badge {{ $al->severity === 'severe' ? 'badge-danger' : '' }}">{{ \App\Modules\Clinical\Models\PatientAllergy::SEVERITIES[$al->severity] }}</span></div>
                @empty
                    <p class="small muted">Nenhuma alergia registrada.</p>
                @endforelse
                <form method="post" action="{{ route('allergies.store', $patient) }}" class="stack">
                    @csrf
                    <label class="sr-only" for="al-sub">Substância</label>
                    <input id="al-sub" name="substance" class="input input-sm" placeholder="Nova alergia (substância)" maxlength="150" required>
                    <div class="row">
                        <label class="sr-only" for="al-sev">Gravidade</label>
                        <select id="al-sev" name="severity" class="input input-sm w-auto">@foreach (\App\Modules\Clinical\Models\PatientAllergy::SEVERITIES as $k => $l)<option value="{{ $k }}" @selected($k === 'unknown')>{{ $l }}</option>@endforeach</select>
                        <button class="btn btn-sm" type="submit" data-save-first>Adicionar</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card__head"><h2>Histórico</h2></div>
            <div class="card__body">
                <ul class="timeline">
                    @forelse ($history as $h)
                        <li><a href="{{ route('encounters.show', $h) }}" target="_blank" rel="noopener"><strong>{{ $h->started_at->timezone($tz)->format('d/m/Y') }}</strong></a> · {{ $h->doctor->displayName() }}
                            @if ($h->diagnoses->isNotEmpty())<div class="small">{{ $h->diagnoses->where('version', $h->current_version)->map(fn ($d) => $d->code)->implode(', ') }}</div>@endif
                            @if ($c = $h->latestVersion?->data['chief_complaint'] ?? null)<div class="small muted">{{ \Illuminate\Support\Str::limit($c, 90) }}</div>@endif
                        </li>
                    @empty
                        <li class="muted small">Primeiro atendimento registrado neste sistema.</li>
                    @endforelse
                </ul>
            </div>
        </section>
    </aside>
</div>
@endsection
