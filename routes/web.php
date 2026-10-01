<?php

use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\UtilityController;
use App\Modules\Audit\Http\Controllers\Web\AuditWebController;
use App\Modules\Clinical\Http\Controllers\Platform\ClinicalCatalogController;
use App\Modules\Clinical\Http\Controllers\Web\ClinicalSupportController;
use App\Modules\Clinical\Http\Controllers\Web\DoctorWorkspaceController;
use App\Modules\Clinical\Http\Controllers\Web\EncounterWebController;
use App\Modules\Doctors\Http\Controllers\Web\DoctorWebController;
use App\Modules\Doctors\Http\Controllers\Web\SpecialtyWebController;
use App\Modules\Identity\Http\Controllers\Web\AccountController;
use App\Modules\Identity\Http\Controllers\Web\LoginController;
use App\Modules\Identity\Http\Controllers\Web\RoleWebController;
use App\Modules\Identity\Http\Controllers\Web\UserWebController;
use App\Modules\Organization\Http\Controllers\Web\BranchWebController;
use App\Modules\Patients\Http\Controllers\Web\PatientWebController;
use App\Modules\Platform\Http\Controllers\Web\CompanySettingsWebController;
use App\Modules\Platform\Http\Controllers\Web\PlatformWebController;
use App\Modules\Printing\Http\Controllers\PrintTestController;
use App\Modules\Queue\Http\Controllers\Web\QueueWebController;
use App\Modules\Scheduling\Http\Controllers\Web\AgendaWebController;
use App\Modules\Scheduling\Http\Controllers\Web\ScheduleConfigWebController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- Autenticação
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'login'])->middleware('throttle:auth')->name('login.attempt');
    Route::get('two-factor-challenge', [LoginController::class, 'showChallenge'])->name('two-factor.challenge');
    Route::post('two-factor-challenge', [LoginController::class, 'challenge'])->middleware('throttle:auth')->name('two-factor.verify');
});

Route::post('logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// ---------------------------------------------------------------- Minha conta
Route::middleware('auth')->prefix('conta')->name('account.')->group(function () {
    Route::get('seguranca', [AccountController::class, 'security'])->name('security');
    Route::put('senha', [AccountController::class, 'updatePassword'])->name('password');
    Route::post('2fa/ativar', [AccountController::class, 'enableTwoFactor'])->name('2fa.enable');
    Route::post('2fa/confirmar', [AccountController::class, 'confirmTwoFactor'])->name('2fa.confirm');
    Route::post('2fa/desativar', [AccountController::class, 'disableTwoFactor'])->name('2fa.disable');
});

// ---------------------------------------------------------------- Clínica
Route::middleware(['auth', 'tenant', '2fa.enrolled'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->middleware('permission:dashboard.visualizar')->name('home');
    Route::post('contexto/filial', [DashboardController::class, 'switchBranch'])->name('context.branch');

    Route::prefix('filiais')->name('branches.')->group(function () {
        Route::get('/', [BranchWebController::class, 'index'])->middleware('permission:filial.visualizar')->name('index');
        Route::get('nova', [BranchWebController::class, 'create'])->middleware('permission:filial.criar')->name('create');
        Route::post('/', [BranchWebController::class, 'store'])->middleware('permission:filial.criar')->name('store');
        Route::get('{branch}/editar', [BranchWebController::class, 'edit'])->middleware('permission:filial.editar')->name('edit');
        Route::put('{branch}', [BranchWebController::class, 'update'])->middleware('permission:filial.editar')->name('update');
        Route::patch('{branch}/status', [BranchWebController::class, 'status'])->middleware('permission:filial.desativar')->name('status');
    });

    Route::prefix('usuarios')->name('users.')->group(function () {
        Route::get('/', [UserWebController::class, 'index'])->middleware('permission:usuario.visualizar')->name('index');
        Route::get('novo', [UserWebController::class, 'create'])->middleware('permission:usuario.criar')->name('create');
        Route::post('/', [UserWebController::class, 'store'])->middleware('permission:usuario.criar')->name('store');
        Route::get('{user}/editar', [UserWebController::class, 'edit'])->middleware('permission:usuario.visualizar')->name('edit');
        Route::put('{user}', [UserWebController::class, 'update'])->middleware('permission:usuario.editar')->name('update');
        Route::put('{user}/perfis', [UserWebController::class, 'syncRoles'])->middleware('permission:usuario.perfis')->name('roles');
        Route::post('{user}/bloquear', [UserWebController::class, 'block'])->middleware('permission:usuario.bloquear')->name('block');
        Route::post('{user}/desbloquear', [UserWebController::class, 'unblock'])->middleware('permission:usuario.bloquear')->name('unblock');
    });

    Route::prefix('perfis')->name('roles.')->group(function () {
        Route::get('/', [RoleWebController::class, 'index'])->middleware('permission:perfil.visualizar')->name('index');
        Route::get('novo', [RoleWebController::class, 'create'])->middleware('permission:perfil.gerenciar')->name('create');
        Route::post('/', [RoleWebController::class, 'store'])->middleware('permission:perfil.gerenciar')->name('store');
        Route::get('{role}/editar', [RoleWebController::class, 'edit'])->middleware('permission:perfil.visualizar')->name('edit');
        Route::put('{role}', [RoleWebController::class, 'update'])->middleware('permission:perfil.gerenciar')->name('update');
        Route::delete('{role}', [RoleWebController::class, 'destroy'])->middleware('permission:perfil.gerenciar')->name('destroy');
    });

    Route::get('auditoria', [AuditWebController::class, 'index'])->middleware('permission:auditoria.visualizar')->name('audit.index');
    Route::get('auditoria/exportar', [AuditWebController::class, 'export'])->middleware('permission:auditoria.exportar')->name('audit.export');

    Route::get('empresa', [CompanySettingsWebController::class, 'edit'])->middleware('permission:empresa.visualizar')->name('company.edit');
    Route::put('empresa', [CompanySettingsWebController::class, 'update'])->middleware('permission:empresa.editar')->name('company.update');

    Route::get('impressao/teste/{format}', PrintTestController::class)->middleware('permission:impressao.configurar')->name('print.test');

    Route::get('busca', [UtilityController::class, 'search'])->name('search');
    Route::get('cep/{cep}', [UtilityController::class, 'cep'])->where('cep', '[0-9\-]{8,9}')->middleware('throttle:30,1')->name('cep');

    // Fase 3 — pacientes, médicos e especialidades
    Route::prefix('pacientes')->name('patients.')->group(function () {
        Route::get('/', [PatientWebController::class, 'index'])->middleware('permission:paciente.visualizar')->name('index');
        Route::get('novo', [PatientWebController::class, 'create'])->middleware('permission:paciente.criar')->name('create');
        Route::post('/', [PatientWebController::class, 'store'])->middleware('permission:paciente.criar')->name('store');
        Route::get('{patient}', [PatientWebController::class, 'show'])->middleware('permission:paciente.visualizar')->name('show');
        Route::get('{patient}/editar', [PatientWebController::class, 'edit'])->middleware('permission:paciente.editar')->name('edit');
        Route::put('{patient}', [PatientWebController::class, 'update'])->middleware('permission:paciente.editar')->name('update');
        Route::patch('{patient}/status', [PatientWebController::class, 'status'])->middleware('permission:paciente.editar')->name('status');
        Route::post('{patient}/consentimentos', [PatientWebController::class, 'consent'])->middleware('permission:paciente.editar')->name('consents');
        Route::get('{patient}/exportar', [PatientWebController::class, 'export'])->middleware('permission:paciente.exportar')->name('export');
        Route::post('{patient}/anonimizar', [PatientWebController::class, 'anonymize'])->middleware('permission:paciente.anonimizar')->name('anonymize');
    });

    Route::prefix('medicos')->name('doctors.')->group(function () {
        Route::get('/', [DoctorWebController::class, 'index'])->middleware('permission:medico.visualizar')->name('index');
        Route::get('novo', [DoctorWebController::class, 'create'])->middleware('permission:medico.gerenciar')->name('create');
        Route::post('/', [DoctorWebController::class, 'store'])->middleware('permission:medico.gerenciar')->name('store');
        Route::get('{doctor}/editar', [DoctorWebController::class, 'edit'])->middleware('permission:medico.visualizar')->name('edit');
        Route::put('{doctor}', [DoctorWebController::class, 'update'])->middleware('permission:medico.gerenciar')->name('update');
        Route::patch('{doctor}/status', [DoctorWebController::class, 'status'])->middleware('permission:medico.gerenciar')->name('status');
    });

    // Fase 4 — agenda, configuração e fila
    Route::prefix('agenda')->name('agenda.')->group(function () {
        Route::get('/', [AgendaWebController::class, 'index'])->middleware('permission:agenda.visualizar')->name('index');
        Route::get('agendar', [AgendaWebController::class, 'create'])->middleware('permission:agenda.criar')->name('create');
        Route::post('/', [AgendaWebController::class, 'store'])->middleware('permission:agenda.criar')->name('store');
        Route::get('pacientes/busca', [AgendaWebController::class, 'patientLookup'])->middleware(['permission:agenda.criar', 'throttle:60,1'])->name('patient_lookup');
        Route::get('feriados', [ScheduleConfigWebController::class, 'holidays'])->middleware('permission:agenda.configurar')->name('holidays');
        Route::post('feriados', [ScheduleConfigWebController::class, 'storeHoliday'])->middleware('permission:agenda.configurar')->name('holidays.store');
        Route::delete('feriados/{holiday}', [ScheduleConfigWebController::class, 'destroyHoliday'])->middleware('permission:agenda.configurar')->name('holidays.destroy');
        Route::post('bloqueios', [ScheduleConfigWebController::class, 'storeBlock'])->middleware('permission:agenda.configurar')->name('blocks.store');
        Route::delete('bloqueios/{block}', [ScheduleConfigWebController::class, 'destroyBlock'])->middleware('permission:agenda.configurar')->name('blocks.destroy');
        Route::get('{appointment}', [AgendaWebController::class, 'show'])->middleware('permission:agenda.visualizar')->name('show');
        Route::post('{appointment}/{action}', [AgendaWebController::class, 'action'])->whereIn('action', ['confirm', 'cancel', 'no-show', 'arrive', 'reschedule'])->name('action');
    });

    Route::prefix('medicos/{doctor}/agenda')->name('doctors.schedule.')->middleware('permission:agenda.configurar')->group(function () {
        Route::get('/', [ScheduleConfigWebController::class, 'doctor'])->name('index');
        Route::post('periodos', [ScheduleConfigWebController::class, 'storeTemplate'])->name('templates.store');
        Route::patch('periodos/{template}/alternar', [ScheduleConfigWebController::class, 'toggleTemplate'])->name('templates.toggle');
        Route::post('servicos', [ScheduleConfigWebController::class, 'storeService'])->name('services.store');
        Route::put('servicos/{service}', [ScheduleConfigWebController::class, 'updateService'])->name('services.update');
        Route::put('limite-diario', [ScheduleConfigWebController::class, 'dailyLimit'])->name('daily_limit');
    });

    Route::get('salas', [ScheduleConfigWebController::class, 'rooms'])->middleware('permission:agenda.configurar')->name('rooms.index');
    Route::post('salas', [ScheduleConfigWebController::class, 'storeRoom'])->middleware('permission:agenda.configurar')->name('rooms.store');
    Route::put('salas/{room}', [ScheduleConfigWebController::class, 'updateRoom'])->middleware('permission:agenda.configurar')->name('rooms.update');

    Route::prefix('fila')->name('queue.')->group(function () {
        Route::get('/', [QueueWebController::class, 'index'])->middleware('permission:fila.visualizar')->name('index');
        Route::post('senhas', [QueueWebController::class, 'issue'])->middleware('permission:fila.gerenciar')->name('issue');
        Route::post('chamar-proxima', [QueueWebController::class, 'callNext'])->middleware('permission:fila.gerenciar')->name('call_next');
        Route::get('senhas/{ticket}/imprimir', [QueueWebController::class, 'print'])->middleware('permission:fila.visualizar')->name('print');
        Route::post('senhas/{ticket}/{action}', [QueueWebController::class, 'action'])->middleware('permission:fila.gerenciar')->whereIn('action', ['call', 'recall', 'start', 'finish', 'skip', 'transfer'])->name('action');
        Route::get('painel', [QueueWebController::class, 'panelSettings'])->middleware('permission:fila.painel')->name('panel');
        Route::put('painel', [QueueWebController::class, 'updatePanel'])->middleware('permission:fila.painel')->name('panel.update');
    });

    // Fase 5 — área do médico, prontuário, triagem e bases clínicas
    Route::get('atendimento', [DoctorWorkspaceController::class, 'index'])->middleware('permission:prontuario.editar')->name('workspace');
    Route::post('atendimento/agendamentos/{appointment}/iniciar', [DoctorWorkspaceController::class, 'start'])->middleware('permission:prontuario.editar')->name('workspace.start');
    Route::post('atendimento/agendamentos/{appointment}/chamar', [DoctorWorkspaceController::class, 'call'])->middleware('permission:fila.chamar|fila.gerenciar')->name('workspace.call');
    Route::post('atendimento/pacientes/{patient}/avulso', [DoctorWorkspaceController::class, 'walkIn'])->middleware('permission:prontuario.editar')->name('workspace.walk_in');

    Route::prefix('prontuario')->name('encounters.')->group(function () {
        Route::get('{encounter}', [EncounterWebController::class, 'show'])->middleware('permission:prontuario.visualizar')->name('show');
        Route::get('{encounter}/editar', [EncounterWebController::class, 'edit'])->middleware('permission:prontuario.editar')->name('edit');
        Route::put('{encounter}/rascunho', [EncounterWebController::class, 'autosave'])->middleware(['permission:prontuario.editar', 'throttle:120,1'])->name('autosave');
        Route::post('{encounter}/finalizar', [EncounterWebController::class, 'finalize'])->middleware('permission:prontuario.finalizar')->name('finalize');
        Route::get('{encounter}/adendo', [EncounterWebController::class, 'addendumForm'])->middleware('permission:prontuario.editar')->name('addendum');
        Route::post('{encounter}/adendo', [EncounterWebController::class, 'storeAddendum'])->middleware('permission:prontuario.editar')->name('addendum.store');
    });

    Route::get('triagem', [ClinicalSupportController::class, 'triageIndex'])->middleware('permission:triagem.registrar')->name('triage.index');
    Route::get('triagem/nova', [ClinicalSupportController::class, 'triageCreate'])->middleware('permission:triagem.registrar')->name('triage.create');
    Route::post('triagem', [ClinicalSupportController::class, 'triageStore'])->middleware('permission:triagem.registrar')->name('triage.store');
    Route::post('pacientes/{patient}/alergias', [ClinicalSupportController::class, 'allergyStore'])->middleware('permission:prontuario.editar|triagem.registrar')->name('allergies.store');
    Route::patch('alergias/{allergy}/inativar', [ClinicalSupportController::class, 'allergyDeactivate'])->middleware('permission:prontuario.editar')->name('allergies.deactivate');
    Route::get('clinico/cid', [ClinicalSupportController::class, 'cidSearch'])->middleware(['permission:prontuario.editar', 'throttle:120,1'])->name('clinical.cid');
    Route::post('clinico/cid/{cid}/favorito', [ClinicalSupportController::class, 'cidFavorite'])->middleware('permission:prontuario.editar')->name('clinical.cid.favorite');
    Route::get('clinico/medicamentos/busca', [ClinicalSupportController::class, 'medicationSearch'])->middleware(['permission:prontuario.editar|medicamento.gerenciar', 'throttle:120,1'])->name('clinical.medications.search');
    Route::get('medicamentos', [ClinicalSupportController::class, 'medications'])->middleware('permission:medicamento.gerenciar')->name('medications.index');
    Route::post('medicamentos', [ClinicalSupportController::class, 'medicationStore'])->middleware('permission:medicamento.gerenciar')->name('medications.store');
    Route::put('medicamentos/{medication}', [ClinicalSupportController::class, 'medicationUpdate'])->middleware('permission:medicamento.gerenciar')->name('medications.update');

    Route::get('especialidades', [SpecialtyWebController::class, 'index'])->middleware('permission:medico.visualizar')->name('specialties.index');
    Route::post('especialidades', [SpecialtyWebController::class, 'store'])->middleware('permission:especialidade.gerenciar')->name('specialties.store');
    Route::put('especialidades/{specialty}', [SpecialtyWebController::class, 'update'])->middleware('permission:especialidade.gerenciar')->name('specialties.update');
});

// ---------------------------------------------------------------- Plataforma SaaS
Route::middleware(['auth', 'platform', '2fa.enrolled'])->prefix('plataforma')->name('platform.')->group(function () {
    Route::get('/', [PlatformWebController::class, 'dashboard'])->name('dashboard');
    Route::get('empresas', [PlatformWebController::class, 'companies'])->name('companies.index');
    Route::get('empresas/nova', [PlatformWebController::class, 'createCompany'])->name('companies.create');
    Route::post('empresas', [PlatformWebController::class, 'storeCompany'])->name('companies.store');
    Route::get('empresas/{company}', [PlatformWebController::class, 'showCompany'])->name('companies.show');
    Route::put('empresas/{company}', [PlatformWebController::class, 'updateCompany'])->name('companies.update');
    Route::get('planos', [PlatformWebController::class, 'plans'])->name('plans.index');
    Route::post('planos', [PlatformWebController::class, 'storePlan'])->name('plans.store');
    Route::put('planos/{plan}', [PlatformWebController::class, 'updatePlan'])->name('plans.update');
    Route::get('bases-clinicas', [ClinicalCatalogController::class, 'index'])->name('catalog.index');
    Route::post('bases-clinicas/cid', [ClinicalCatalogController::class, 'importCid'])->name('catalog.cid');
    Route::post('bases-clinicas/medicamentos', [ClinicalCatalogController::class, 'importMedications'])->name('catalog.medications');
});
