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
        <div class="card__head"><h2>Convênios</h2>
            @if ($patient->insurances->whereNotNull('insurer_id')->isNotEmpty())
                <div class="row">
                    @if (auth()->user()->hasPermission('convenio.autorizar') || auth()->user()->hasPermission('convenio.faturar'))<a class="btn btn-sm" href="{{ route('authorizations.index', ['patient_id' => $patient->id]) }}">Solicitar autorização</a>@endif
                    @if (auth()->user()->hasPermission('convenio.faturar'))<a class="btn btn-sm" href="{{ route('guides.create', ['patient_id' => $patient->id]) }}">Guia avulsa</a>@endif
                </div>
            @endif</div>
        <div class="card__body stack">
            @forelse ($patient->insurances as $i)
                <div class="spread">
                    <div><strong>{{ $i->insurer_name }}</strong> {{ $i->plan_name ? '· '.$i->plan_name : '' }} @unless ($i->insurer_id)<span class="badge" title="Vincule ao convênio cadastrado para faturar">não vinculado</span>@endunless @if ($i->is_primary)<span class="badge badge-primary">principal</span>@endif
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


    @if ($me->hasPermission('paciente.portal') && ! $patient->isAnonymized())
    <section class="card">
        <div class="card__head"><h2>Portal do paciente</h2>
            @if ($portalAccount)<span class="badge {{ ['active' => 'badge-success', 'blocked' => 'badge-danger'][$portalAccount->status] ?? 'badge-warning' }}">{{ $portalAccount->statusLabel() }}</span>@endif</div>
        <div class="card__body stack">
            @if ($link = session('portal_link'))
                <div class="alert alert-info stack">
                    <strong>{{ $link['purpose'] === 'activation' ? 'Link de ativação' : 'Link para nova senha' }}</strong> — válido até {{ \Carbon\Carbon::parse($link['expires_at'])->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}, uso único. Ele não fica salvo: copie ou envie agora.
                    <input id="portal-link" class="input mono" value="{{ $link['url'] }}" readonly aria-label="Link do portal">
                    <span class="row">
                        <button class="btn btn-sm" type="button" data-copy="#portal-link">Copiar link</button>
                        @if ($patient->whatsapp)<a class="btn btn-sm" target="_blank" rel="noopener noreferrer" href="https://wa.me/55{{ $patient->whatsapp }}?text={{ rawurlencode('Olá! Este é o seu link de acesso ao portal do paciente: '.$link['url']) }}">Enviar pelo WhatsApp</a>@endif
                        @if ($link['emailed'])<span class="small">E-mail enviado para {{ $portalAccount?->email }}.</span>@endif
                    </span>
                </div>
            @endif
            @if ($portalAccount)
                <p class="small">Login: CPF{{ $portalAccount->email ? ' ou '.$portalAccount->email : '' }}{{ $portalAccount->last_login_at ? ' · último acesso '.$portalAccount->last_login_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : '' }}</p>
            @else
                <p class="small muted">Sem acesso ao portal. Gere o link de ativação para o paciente criar a senha.</p>
            @endif
            @if (! $patient->cpf)<p class="small text-danger">Sem CPF cadastrado: o paciente só conseguirá entrar com o e-mail.</p>@endif
            @if ($portalAccount?->status !== 'blocked')
                <form method="post" action="{{ route('patients.portal.link', $patient) }}" class="form-grid">
                    @csrf
                    <x-field name="email" label="E-mail do paciente (login e envio do link)" type="email" col="col-12" :value="$portalAccount?->email ?? $patient->email" />
                    <label class="check col-12 small"><input type="checkbox" name="send_email" value="1"><span>Enviar o link por e-mail</span></label>
                    <label class="check col-12 small"><input type="checkbox" name="send_whatsapp" value="1"><span>Enviar pelo WhatsApp da clínica (modelo aprovado; exige consentimento de WhatsApp)</span></label>
                    <div class="col-12"><button class="btn btn-sm btn-primary" type="submit">{{ $portalAccount?->isActive() ? 'Gerar link de nova senha' : 'Gerar link de ativação' }}</button></div>
                </form>
            @endif
            @if ($portalAccount)
                <form method="post" action="{{ route('patients.portal.block', $patient) }}" data-confirm="{{ $portalAccount->status === 'blocked' ? 'Desbloquear' : 'Bloquear' }} o acesso ao portal?">@csrf
                    <button class="btn btn-sm btn-ghost" type="submit">{{ $portalAccount->status === 'blocked' ? 'Desbloquear acesso' : 'Bloquear acesso' }}</button></form>
            @endif
        </div>
    </section>
    @endif

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

@if ($encounters !== null || $allergies !== null)
<div class="grid grid-2 mt-2">
    @if ($encounters !== null)
        <section class="card">
            <div class="card__head"><h2>Atendimentos (prontuário)</h2>
                @if (! $patient->isAnonymized() && $patient->status === 'active' && $me->hasPermission('prontuario.editar'))
                    <form method="post" action="{{ route('workspace.walk_in', $patient) }}" data-confirm="Iniciar um atendimento avulso (sem agendamento) para este paciente?">@csrf<button class="btn btn-sm btn-primary" type="submit">Iniciar atendimento avulso</button></form>
                @endif
            </div>
            <div class="card__body"><ul class="timeline">
                @forelse ($encounters as $e)
                    <li><a href="{{ route('encounters.show', $e) }}"><strong>{{ $e->started_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</strong></a>
                        · {{ $e->doctor->displayName() }} · {{ $e->branch->name }}
                        @if ($e->isDraft())<span class="badge badge-warning">em andamento</span>@elseif ($e->current_version > 1)<span class="badge">{{ $e->current_version - 1 }} adendo(s)</span>@endif
                        @if ($e->diagnoses->isNotEmpty())<div class="small">{{ $e->diagnoses->where('version', $e->current_version)->map(fn ($d) => $d->code.' '.$d->description)->implode('; ') }}</div>@endif
                    </li>
                @empty
                    <li class="muted small">Nenhum atendimento registrado.</li>
                @endforelse
            </ul></div>
        </section>
    @endif
    @if ($allergies !== null)
        <section class="card">
            <div class="card__head"><h2>Alergias</h2></div>
            <div class="card__body stack">
                @forelse ($allergies as $al)
                    <div class="spread small {{ $al->status === 'active' ? '' : 'muted' }}">
                        <span><strong>{{ $al->substance }}</strong>{{ $al->reaction ? ' — '.$al->reaction : '' }} <span class="badge {{ $al->severity === 'severe' ? 'badge-danger' : '' }}">{{ \App\Modules\Clinical\Models\PatientAllergy::SEVERITIES[$al->severity] }}</span>{{ $al->status === 'active' ? '' : ' (inativa)' }}</span>
                        @if ($al->status === 'active' && $me->hasPermission('prontuario.editar'))
                            <form method="post" action="{{ route('allergies.deactivate', $al) }}" data-confirm="Marcar esta alergia como inativa?">@csrf @method('patch')<button class="btn btn-sm btn-ghost" type="submit">Inativar</button></form>
                        @endif
                    </div>
                @empty
                    <p class="small muted">Nenhuma alergia registrada.</p>
                @endforelse
                @unless ($patient->isAnonymized())
                    <form method="post" action="{{ route('allergies.store', $patient) }}" class="row">
                        @csrf
                        <label class="sr-only" for="al-sub">Substância</label><input id="al-sub" name="substance" class="input input-sm w-auto" placeholder="Substância" maxlength="150" required>
                        <label class="sr-only" for="al-rea">Reação</label><input id="al-rea" name="reaction" class="input input-sm w-auto" placeholder="Reação" maxlength="255">
                        <label class="sr-only" for="al-sev">Gravidade</label><select id="al-sev" name="severity" class="input input-sm w-auto">@foreach (\App\Modules\Clinical\Models\PatientAllergy::SEVERITIES as $k => $l)<option value="{{ $k }}" @selected($k === 'unknown')>{{ $l }}</option>@endforeach</select>
                        <button class="btn btn-sm" type="submit">Adicionar</button>
                    </form>
                @endunless
            </div>
        </section>
    @endif
</div>
@endif

@if ($documents !== null || $files !== null)
<div class="grid grid-2 mt-2">
    @if ($documents !== null)
        <section class="card">
            <div class="card__head"><h2>Documentos emitidos</h2><a class="btn btn-sm" href="{{ route('documents.index', ['patient_id' => $patient->id]) }}">Ver todos</a></div>
            <div class="card__body stack">
                @if (! $patient->isAnonymized() && $patient->status === 'active')
                    <div class="row">
                        @if ($me->hasPermission('receita.emitir'))<a class="btn btn-sm" href="{{ route('documents.create', ['type' => 'prescription', 'patient_id' => $patient->id]) }}">Receita</a>@endif
                        @if ($me->hasPermission('atestado.emitir'))<a class="btn btn-sm" href="{{ route('documents.create', ['type' => 'certificate', 'patient_id' => $patient->id]) }}">Atestado</a>@endif
                        @if ($me->hasPermission('exame.solicitar'))<a class="btn btn-sm" href="{{ route('documents.create', ['type' => 'exam_request', 'patient_id' => $patient->id]) }}">Exames</a>@endif
                    </div>
                @endif
                @forelse ($documents as $d)
                    <div class="spread small {{ $d->isCancelled() ? 'muted' : '' }}">
                        <span><a href="{{ route('documents.show', $d) }}">{{ $d->typeLabel() }} {{ $d->displayNumber() }}</a> · {{ $d->doctor->displayName() }}</span>
                        <span>{{ $d->issued_at->timezone('America/Sao_Paulo')->format('d/m/Y') }} @if ($d->isCancelled())<span class="badge badge-danger">cancelado</span>@endif</span>
                    </div>
                @empty
                    <p class="small muted">Nenhum documento emitido.</p>
                @endforelse
            </div>
        </section>
    @endif
    @if ($files !== null)
        <section class="card">
            <div class="card__head"><h2>Arquivos e exames anexados</h2></div>
            <div class="card__body stack">
                @php $canView = $me->hasPermission('documento.visualizar') || $me->hasPermission('prontuario.visualizar'); @endphp
                @forelse ($files as $f)
                    <div class="spread small {{ $f->status === 'archived' ? 'muted' : '' }}">
                        <span>@if ($canView)<a href="{{ route('patient_files.download', [$f, 'inline' => 1]) }}" target="_blank" rel="noopener">{{ $f->title }}</a>@else{{ $f->title }}@endif
                            <span class="muted">· {{ \App\Modules\Documents\Models\PatientFile::CATEGORIES[$f->category] }} · {{ $f->sizeLabel() }} · {{ $f->created_at->timezone('America/Sao_Paulo')->format('d/m/Y') }} · {{ $f->uploader?->name }}</span>
                            @if ($f->status === 'archived')<span class="badge">arquivado</span>@endif
                            @if ($f->visible_to_patient)<span class="badge badge-info">no portal</span>@endif</span>
                        @if ($me->hasPermission('documento.anexar'))
                            <span class="row">
                            <form method="post" action="{{ route('patient_files.share', $f) }}">@csrf @method('patch')<button class="btn btn-sm btn-ghost" type="submit">{{ $f->visible_to_patient ? 'Tirar do portal' : 'Liberar no portal' }}</button></form>
                            <form method="post" action="{{ route('patient_files.archive', $f) }}">@csrf @method('patch')<button class="btn btn-sm btn-ghost" type="submit">{{ $f->status === 'active' ? 'Arquivar' : 'Restaurar' }}</button></form>
                            </span>
                        @endif
                    </div>
                @empty
                    <p class="small muted">Nenhum arquivo anexado.</p>
                @endforelse
                @if (! $patient->isAnonymized() && $me->hasPermission('documento.anexar'))
                    <form method="post" action="{{ route('patient_files.store', $patient) }}" enctype="multipart/form-data" class="stack">
                        @csrf
                        <div class="row">
                            <label class="sr-only" for="pf-file">Arquivo</label>
                            <input id="pf-file" type="file" name="file" class="input" accept="application/pdf,image/jpeg,image/png,image/webp" required>
                            <label class="sr-only" for="pf-cat">Categoria</label>
                            <select id="pf-cat" name="category" class="input w-auto">@foreach (\App\Modules\Documents\Models\PatientFile::CATEGORIES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                        </div>
                        <div class="row">
                            <label class="sr-only" for="pf-title">Título</label>
                            <input id="pf-title" name="title" class="input" maxlength="150" placeholder="Título (ex.: Hemograma 10/2026)">
                            <button class="btn" type="submit">Anexar</button>
                        </div>
                        @error('file')<div class="field-error">{{ $message }}</div>@enderror
                        <p class="help">PDF, JPG, PNG ou WEBP até {{ (int) (config('aivexa.uploads.max_kb', 10240) / 1024) }} MB. Arquivos ficam em área privada e cada acesso é registrado.</p>
                    </form>
                @endif
            </div>
        </section>
    @endif
</div>
@endif

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
