@extends('layouts.app', ['title' => $patient->displayName()])

@php
    use App\Core\Support\Format;
    $me = auth()->user();
    $consents = $patient->currentConsents();
@endphp

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $patient->displayName() }}</h1>
        <p>Prontuário <span class="record-no">#{{ $patient->record_number }}</span>
            @if ($patient->age() !== null) · {{ $patient->age() }} anos @endif
            @if ($patient->isMinor())<span class="badge badge-info">menor de idade</span>@endif
            @if ($patient->status === 'inactive')<span class="badge">inativo</span>@endif
            @if ($patient->isAnonymized())<span class="badge badge-warning">anonimizado em {{ $patient->anonymized_at->timezone('America/Sao_Paulo')->format('d/m/Y') }}</span>@endif
        </p>
    </div>
    <div class="row">
        @if (! $patient->isAnonymized() && $me->hasPermission('paciente.editar'))
            <a class="btn btn-primary" href="{{ route('patients.edit', $patient) }}">Editar cadastro</a>
        @endif
        <a class="btn" href="{{ route('patients.index') }}">Voltar</a>
    </div>
</div>

<div class="grid grid-2">
    <section class="card">
        <div class="card__head"><h2>Dados cadastrais</h2></div>
        <div class="card__body">
            <dl class="dl">
                @if ($patient->social_name)<dt>Nome social</dt><dd>{{ $patient->social_name }}</dd><dt>Registro civil</dt><dd>{{ $patient->name }}</dd>@else<dt>Nome</dt><dd>{{ $patient->name }}</dd>@endif
                <dt>Nascimento</dt><dd>{{ $patient->birth_date?->format('d/m/Y') ?? '—' }}</dd>
                <dt>Sexo</dt><dd>{{ \App\Modules\Patients\Models\Patient::SEXES[$patient->sex] ?? '—' }}{{ $patient->gender_identity ? ' · '.$patient->gender_identity : '' }}</dd>
                <dt>CPF</dt><dd class="mono">{{ Format::cpf($patient->cpf) ?? '—' }}</dd>
                <dt>RG</dt><dd>{{ $patient->rg ? $patient->rg.' '.$patient->rg_issuer : '—' }}</dd>
                <dt>Cartão SUS</dt><dd class="mono">{{ $patient->cns ?? '—' }}</dd>
                <dt>Nome da mãe</dt><dd>{{ $patient->mother_name ?? '—' }}</dd>
                <dt>Unidade de cadastro</dt><dd>{{ $patient->homeBranch?->name ?? '—' }}</dd>
            </dl>
            @if ($patient->notes)<p class="mt-2 small text-2"><strong>Observações:</strong> {{ $patient->notes }}</p>@endif
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Contato</h2></div>
        <div class="card__body">
            <dl class="dl">
                <dt>WhatsApp</dt><dd>{{ Format::phone($patient->whatsapp) ?? '—' }}</dd>
                <dt>Telefone</dt><dd>{{ Format::phone($patient->phone) ?? '—' }}</dd>
                <dt>E-mail</dt><dd>{{ $patient->email ?? '—' }}</dd>
                <dt>Preferência</dt><dd>{{ ['whatsapp' => 'WhatsApp', 'phone' => 'Telefone', 'email' => 'E-mail'][$patient->preferred_contact] ?? '—' }}</dd>
                <dt>Endereço</dt><dd>{{ collect([trim(($patient->street ?? '').', '.($patient->number ?? ''), ', '), $patient->complement, $patient->district, trim(($patient->city ?? '').' - '.($patient->state ?? ''), ' -'), Format::cep($patient->zip_code)])->filter()->implode(' · ') ?: '—' }}</dd>
            </dl>
            <h3 class="section-title">Responsáveis e contatos</h3>
            @forelse ($patient->contacts as $c)
                <div class="small"><span class="badge {{ $c->type === 'guardian' ? 'badge-primary' : '' }}">{{ \App\Modules\Patients\Models\PatientContact::TYPES[$c->type] }}</span>
                    <strong>{{ $c->name }}</strong> {{ $c->relationship ? '('.$c->relationship.')' : '' }} · {{ Format::phone($c->phone) ?? 'sem telefone' }}</div>
            @empty
                <p class="small muted">Nenhum contato cadastrado.</p>
            @endforelse
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Convênios</h2></div>
        <div class="card__body stack">
            @forelse ($patient->insurances as $i)
                <div class="spread">
                    <div><strong>{{ $i->insurer_name }}</strong> {{ $i->plan_name ? '· '.$i->plan_name : '' }} @if ($i->is_primary)<span class="badge badge-primary">principal</span>@endif
                        <div class="small muted mono">{{ $i->card_number }}</div></div>
                    <div class="small">@if ($i->valid_until)
                        @if ($i->isExpired())<span class="badge badge-danger">vencida {{ $i->valid_until->format('d/m/Y') }}</span>@else<span class="muted">válida até {{ $i->valid_until->format('d/m/Y') }}</span>@endif
                    @endif</div>
                </div>
            @empty
                <p class="small muted">Particular (sem convênio cadastrado).</p>
            @endforelse
        </div>
    </section>

    <section class="card">
        <div class="card__head"><h2>Consentimentos (LGPD)</h2></div>
        <div class="card__body">
            <p class="help">O atendimento de saúde não depende de consentimento; estes itens tratam de comunicações e usos opcionais.</p>
            <div class="table-wrap"><table class="table"><tbody>
                @foreach (config('consents.purposes') as $key => $purpose)
                    @php $c = $consents[$key] ?? null; @endphp
                    <tr>
                        <td class="small">{{ $purpose['label'] }}
                            @if ($c)<div class="muted">{{ $c->granted ? 'Concedido' : 'Revogado' }} em {{ $c->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} · {{ config('consents.channels')[$c->channel] ?? $c->channel }} · termo v{{ $c->term_version }} · {{ $c->recorder?->name }}</div>@endif</td>
                        <td>@if ($c?->granted)<span class="badge badge-success">sim</span>@elseif ($c)<span class="badge badge-danger">não</span>@else<span class="badge">não informado</span>@endif</td>
                        <td class="actions">
                            @if (! $patient->isAnonymized() && $me->hasPermission('paciente.editar'))
                                <form method="post" action="{{ route('patients.consents', $patient) }}" class="row">
                                    @csrf
                                    <input type="hidden" name="purpose" value="{{ $key }}">
                                    <input type="hidden" name="granted" value="{{ $c?->granted ? 0 : 1 }}">
                                    <label class="sr-only" for="ch-{{ $key }}">Canal</label>
                                    <select id="ch-{{ $key }}" name="channel" class="input input-sm">
                                        @foreach (config('consents.channels') as $ck => $cl)<option value="{{ $ck }}">{{ $cl }}</option>@endforeach
                                    </select>
                                    <button class="btn btn-sm" type="submit">{{ $c?->granted ? 'Revogar' : 'Registrar aceite' }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody></table></div>
        </div>
    </section>
</div>

<section class="card mt-2">
    <div class="card__head"><h2>Histórico de alterações do cadastro</h2></div>
    <div class="card__body">
        <ul class="timeline">
            @forelse ($history as $log)
                <li>
                    <div class="spread"><span>@include('partials.audit-action', ['log' => $log])</span>
                        <span class="small muted">{{ $log->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} · {{ $log->user?->name ?? 'Sistema' }}</span></div>
                    @if ($log->new_values && $log->action === 'patient.updated')
                        <div class="small text-2">Campos alterados: {{ implode(', ', array_keys($log->new_values)) }}</div>
                    @endif
                </li>
            @empty
                <li class="muted">Sem registros.</li>
            @endforelse
        </ul>
    </div>
</section>

@if (! $patient->isAnonymized())
    <div class="grid grid-2 mt-2">
        @if ($me->hasPermission('paciente.editar'))
            <section class="card">
                <div class="card__head"><h2>Situação do cadastro</h2></div>
                <div class="card__body">
                    <form method="post" action="{{ route('patients.status', $patient) }}" data-confirm="{{ $patient->status === 'active' ? 'Inativar este paciente?' : 'Reativar este paciente?' }}">
                        @csrf @method('patch')
                        <input type="hidden" name="status" value="{{ $patient->status === 'active' ? 'inactive' : 'active' }}">
                        <p class="small text-2">Pacientes nunca são excluídos (retenção legal do prontuário). Inativar apenas oculta das buscas padrão.</p>
                        <button class="btn" type="submit">{{ $patient->status === 'active' ? 'Inativar paciente' : 'Reativar paciente' }}</button>
                    </form>
                </div>
            </section>
        @endif

        @if ($me->hasPermission('paciente.exportar') || $me->hasPermission('paciente.anonimizar'))
            <section class="card danger-zone">
                <div class="card__head"><h2>Direitos do titular (LGPD)</h2></div>
                <div class="card__body stack">
                    @if ($me->hasPermission('paciente.exportar'))
                        <div><a class="btn" href="{{ route('patients.export', $patient) }}">Exportar dados do paciente (JSON)</a>
                            <p class="help">Atende pedidos de acesso/portabilidade. A exportação é registrada na auditoria.</p></div>
                    @endif
                    @if ($me->hasPermission('paciente.anonimizar'))
                        <form method="post" action="{{ route('patients.anonymize', $patient) }}" class="stack" data-confirm="A anonimização é IRREVERSÍVEL. Confirmar?">
                            @csrf
                            <label class="label" for="an-reason">Anonimizar — motivo / protocolo da solicitação</label>
                            <input id="an-reason" name="reason" class="input @error('reason') is-invalid @enderror" minlength="10" maxlength="255" required>
                            @error('reason')<div class="field-error">{{ $message }}</div>@enderror
                            <label class="check small"><input type="checkbox" name="confirm" value="1" required><span>Entendo que os dados de identificação serão removidos definitivamente. O nº de prontuário e os registros clínicos obrigatórios são preservados.</span></label>
                            <div><button class="btn btn-danger" type="submit">Anonimizar paciente</button></div>
                        </form>
                    @endif
                </div>
            </section>
        @endif
    </div>
@endif
@endsection
