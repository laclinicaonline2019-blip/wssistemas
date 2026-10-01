@php
    $labels = [
        'auth.login' => 'Login', 'auth.logout' => 'Logout', 'auth.login.failed' => 'Falha de login',
        'auth.login.throttled' => 'Login limitado (rate limit)', 'auth.account.locked' => 'Conta bloqueada (tentativas)',
        'auth.2fa.enabled' => '2FA ativado', 'auth.2fa.disabled' => '2FA desativado', 'auth.2fa.recovery_code_used' => 'Código de recuperação usado',
        'auth.password_changed' => 'Senha alterada', 'access.denied' => 'Acesso negado', 'access.branch_denied' => 'Filial negada',
        'access.platform_denied' => 'Acesso à plataforma negado',
        'user.created' => 'Usuário criado', 'user.updated' => 'Usuário alterado', 'user.blocked' => 'Usuário bloqueado',
        'user.unblocked' => 'Usuário desbloqueado', 'user.password_reset_by_admin' => 'Senha redefinida pelo administrador',
        'branch.created' => 'Filial criada', 'branch.updated' => 'Filial alterada',
        'role.created' => 'Perfil criado', 'role.updated' => 'Perfil alterado', 'role.deleted' => 'Perfil excluído',
        'role.permissions_changed' => 'Permissões do perfil alteradas',
        'role_assignment.created' => 'Perfil atribuído', 'role_assignment.deleted' => 'Perfil removido',
        'company.created' => 'Empresa criada', 'company.updated' => 'Empresa alterada', 'company.provisioned' => 'Clínica provisionada',
        'audit.exported' => 'Auditoria exportada',
        'patient.created' => 'Paciente cadastrado', 'patient.updated' => 'Cadastro do paciente alterado',
        'patient.viewed' => 'Cadastro do paciente acessado', 'patient.exported' => 'Dados do paciente exportados (LGPD)',
        'patient.anonymized' => 'Paciente anonimizado (LGPD)', 'patient.consent_granted' => 'Consentimento concedido',
        'patient.consent_revoked' => 'Consentimento revogado', 'patient.contacts_changed' => 'Contatos/responsáveis alterados',
        'patient.insurances_changed' => 'Convênios alterados',
        'doctor.created' => 'Médico cadastrado', 'doctor.updated' => 'Médico alterado',
        'doctor.specialties_changed' => 'Especialidades do médico alteradas', 'doctor.branches_changed' => 'Filiais do médico alteradas',
        'specialty.created' => 'Especialidade criada', 'specialty.updated' => 'Especialidade alterada',
        'medical_record.started' => 'Atendimento iniciado', 'medical_record.viewed' => 'Prontuário acessado',
        'medical_record.finalized' => 'Atendimento finalizado (prontuário)', 'medical_record.addendum' => 'Adendo ao prontuário',
        'triage.recorded' => 'Triagem registrada', 'patient_allergy.created' => 'Alergia registrada', 'patient_allergy.updated' => 'Alergia alterada',
        'medication.created' => 'Medicamento cadastrado', 'medication.updated' => 'Medicamento alterado',
        'catalog.cid_imported' => 'Base CID importada', 'catalog.medications_imported' => 'Base de medicamentos importada',
    ];
    $cls = ['success' => 'badge-success', 'failure' => 'badge-danger', 'denied' => 'badge-warning'][$log->result] ?? '';
@endphp
<span class="nowrap">{{ $labels[$log->action] ?? $log->action }}</span>
@if ($log->result !== 'success')<span class="badge {{ $cls }}">{{ $log->result === 'denied' ? 'negado' : 'falha' }}</span>@endif
