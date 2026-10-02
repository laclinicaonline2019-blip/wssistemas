<?php

use App\Modules\Audit\Http\Controllers\Api\AuditLogController;
use App\Modules\Clinical\Http\Controllers\Api\ClinicalController;
use App\Modules\Clinical\Http\Controllers\Api\EncounterController;
use App\Modules\Doctors\Http\Controllers\Api\DoctorController;
use App\Modules\Doctors\Http\Controllers\Api\SpecialtyController;
use App\Modules\Documents\Http\Controllers\Api\DocumentController;
use App\Modules\Finance\Http\Controllers\Api\FinanceController;
use App\Modules\Identity\Http\Controllers\Api\AuthController;
use App\Modules\Identity\Http\Controllers\Api\RoleController;
use App\Modules\Identity\Http\Controllers\Api\TwoFactorController;
use App\Modules\Identity\Http\Controllers\Api\UserController;
use App\Modules\Organization\Http\Controllers\Api\BranchController;
use App\Modules\Patients\Http\Controllers\Api\PatientController;
use App\Modules\Payments\Http\Controllers\Api\ChargeController;
use App\Modules\Platform\Http\Controllers\Api\CompanySettingsController;
use App\Modules\Platform\Http\Controllers\Api\PlatformCompanyController;
use App\Modules\Platform\Http\Controllers\Api\PlatformHealthController;
use App\Modules\Platform\Http\Controllers\Api\PlatformPlanController;
use App\Modules\Queue\Http\Controllers\Api\QueueController;
use App\Modules\Scheduling\Http\Controllers\Api\AppointmentController;
use App\Modules\Scheduling\Http\Controllers\Api\AvailabilityController;
use App\Modules\Scheduling\Http\Controllers\Api\ScheduleConfigController;
use Illuminate\Support\Facades\Route;

/*
| API REST v1 — autenticação por token Bearer (Sanctum).
| Toda autorização é validada no backend: autenticação → tenant → permissão.
*/

Route::prefix('v1')->name('api.')->group(function () {

    Route::post('auth/token', [AuthController::class, 'token'])->middleware('throttle:auth')->name('auth.token');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

        Route::prefix('auth')->name('auth.')->group(function () {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('two-factor/setup', [TwoFactorController::class, 'setup'])->name('2fa.setup');
            Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('2fa.confirm');
            Route::post('two-factor/disable', [TwoFactorController::class, 'disable'])->name('2fa.disable');
        });

        // ------------------------------------------------------------ Clínica
        Route::middleware(['tenant', '2fa.enrolled'])->group(function () {
            Route::get('company', [CompanySettingsController::class, 'show'])->middleware('permission:empresa.visualizar')->name('company.show');
            Route::patch('company', [CompanySettingsController::class, 'update'])->middleware('permission:empresa.editar')->name('company.update');

            Route::get('branches', [BranchController::class, 'index'])->middleware('permission:filial.visualizar')->name('branches.index');
            Route::post('branches', [BranchController::class, 'store'])->middleware('permission:filial.criar')->name('branches.store');
            Route::get('branches/{branch}', [BranchController::class, 'show'])->middleware('permission:filial.visualizar')->name('branches.show');
            Route::patch('branches/{branch}', [BranchController::class, 'update'])->middleware('permission:filial.editar')->name('branches.update');
            Route::patch('branches/{branch}/status', [BranchController::class, 'status'])->middleware('permission:filial.desativar')->name('branches.status');

            Route::get('users', [UserController::class, 'index'])->middleware('permission:usuario.visualizar')->name('users.index');
            Route::post('users', [UserController::class, 'store'])->middleware('permission:usuario.criar')->name('users.store');
            Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:usuario.visualizar')->name('users.show');
            Route::patch('users/{user}', [UserController::class, 'update'])->middleware('permission:usuario.editar')->name('users.update');
            Route::post('users/{user}/block', [UserController::class, 'block'])->middleware('permission:usuario.bloquear')->name('users.block');
            Route::post('users/{user}/unblock', [UserController::class, 'unblock'])->middleware('permission:usuario.bloquear')->name('users.unblock');
            Route::put('users/{user}/roles', [UserController::class, 'syncRoles'])->middleware('permission:usuario.perfis')->name('users.roles');

            Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:perfil.visualizar')->name('permissions.index');
            Route::get('roles', [RoleController::class, 'index'])->middleware('permission:perfil.visualizar|usuario.perfis')->name('roles.index');
            Route::post('roles', [RoleController::class, 'store'])->middleware('permission:perfil.gerenciar')->name('roles.store');
            Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:perfil.visualizar')->name('roles.show');
            Route::patch('roles/{role}', [RoleController::class, 'update'])->middleware('permission:perfil.gerenciar')->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:perfil.gerenciar')->name('roles.destroy');

            Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:auditoria.visualizar')->name('audit.index');

            // Fase 3 — especialidades, médicos e pacientes
            Route::get('specialties', [SpecialtyController::class, 'index'])->middleware('permission:medico.visualizar|agenda.visualizar')->name('specialties.index');
            Route::post('specialties', [SpecialtyController::class, 'store'])->middleware('permission:especialidade.gerenciar')->name('specialties.store');
            Route::patch('specialties/{specialty}', [SpecialtyController::class, 'update'])->middleware('permission:especialidade.gerenciar')->name('specialties.update');

            Route::get('doctors', [DoctorController::class, 'index'])->middleware('permission:medico.visualizar|agenda.visualizar')->name('doctors.index');
            Route::post('doctors', [DoctorController::class, 'store'])->middleware('permission:medico.gerenciar')->name('doctors.store');
            Route::get('doctors/{doctor}', [DoctorController::class, 'show'])->middleware('permission:medico.visualizar|agenda.visualizar')->name('doctors.show');
            Route::patch('doctors/{doctor}', [DoctorController::class, 'update'])->middleware('permission:medico.gerenciar')->name('doctors.update');
            Route::patch('doctors/{doctor}/status', [DoctorController::class, 'status'])->middleware('permission:medico.gerenciar')->name('doctors.status');

            Route::get('patients', [PatientController::class, 'index'])->middleware('permission:paciente.visualizar')->name('patients.index');
            Route::post('patients', [PatientController::class, 'store'])->middleware('permission:paciente.criar')->name('patients.store');
            Route::get('patients/{patient}', [PatientController::class, 'show'])->middleware('permission:paciente.visualizar')->name('patients.show');
            Route::patch('patients/{patient}', [PatientController::class, 'update'])->middleware('permission:paciente.editar')->name('patients.update');
            Route::patch('patients/{patient}/status', [PatientController::class, 'status'])->middleware('permission:paciente.editar')->name('patients.status');
            Route::post('patients/{patient}/consents', [PatientController::class, 'consent'])->middleware('permission:paciente.editar')->name('patients.consents');
            Route::get('patients/{patient}/export', [PatientController::class, 'export'])->middleware('permission:paciente.exportar')->name('patients.export');
            Route::post('patients/{patient}/anonymize', [PatientController::class, 'anonymize'])->middleware('permission:paciente.anonimizar')->name('patients.anonymize');

            // Fase 4 — agenda, disponibilidade e fila
            Route::get('availability/slots', [AvailabilityController::class, 'slots'])->middleware('permission:agenda.visualizar')->name('availability.slots');
            Route::get('availability/next', [AvailabilityController::class, 'next'])->middleware('permission:agenda.visualizar')->name('availability.next');

            Route::get('appointments', [AppointmentController::class, 'index'])->middleware('permission:agenda.visualizar')->name('appointments.index');
            Route::post('appointments', [AppointmentController::class, 'store'])->middleware('permission:agenda.criar')->name('appointments.store');
            Route::get('appointments/{appointment}', [AppointmentController::class, 'show'])->middleware('permission:agenda.visualizar')->name('appointments.show');
            Route::post('appointments/{appointment}/confirm', [AppointmentController::class, 'confirm'])->middleware('permission:agenda.editar')->name('appointments.confirm');
            Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->middleware('permission:agenda.cancelar')->name('appointments.cancel');
            Route::post('appointments/{appointment}/no-show', [AppointmentController::class, 'noShow'])->middleware('permission:agenda.editar')->name('appointments.no_show');
            Route::post('appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->middleware('permission:agenda.editar')->name('appointments.reschedule');
            Route::post('appointments/{appointment}/arrive', [AppointmentController::class, 'arrive'])->middleware('permission:fila.gerenciar')->name('appointments.arrive');

            Route::middleware('permission:agenda.configurar')->group(function () {
                Route::get('doctors/{doctor}/schedule-templates', [ScheduleConfigController::class, 'templates'])->name('schedule.templates');
                Route::post('doctors/{doctor}/schedule-templates', [ScheduleConfigController::class, 'storeTemplate'])->name('schedule.templates.store');
                Route::patch('doctors/{doctor}/schedule-templates/{template}', [ScheduleConfigController::class, 'updateTemplate'])->name('schedule.templates.update');
                Route::get('doctors/{doctor}/services', [ScheduleConfigController::class, 'services'])->name('schedule.services');
                Route::post('doctors/{doctor}/services', [ScheduleConfigController::class, 'storeService'])->name('schedule.services.store');
                Route::patch('doctors/{doctor}/services/{service}', [ScheduleConfigController::class, 'updateService'])->name('schedule.services.update');
                Route::get('schedule-blocks', [ScheduleConfigController::class, 'blocks'])->name('schedule.blocks');
                Route::post('schedule-blocks', [ScheduleConfigController::class, 'storeBlock'])->name('schedule.blocks.store');
                Route::delete('schedule-blocks/{block}', [ScheduleConfigController::class, 'destroyBlock'])->name('schedule.blocks.destroy');
                Route::get('holidays', [ScheduleConfigController::class, 'holidays'])->name('holidays.index');
                Route::post('holidays', [ScheduleConfigController::class, 'storeHoliday'])->name('holidays.store');
                Route::delete('holidays/{holiday}', [ScheduleConfigController::class, 'destroyHoliday'])->name('holidays.destroy');
                Route::get('rooms', [ScheduleConfigController::class, 'rooms'])->name('rooms.index');
                Route::post('rooms', [ScheduleConfigController::class, 'storeRoom'])->name('rooms.store');
                Route::patch('rooms/{room}', [ScheduleConfigController::class, 'updateRoom'])->name('rooms.update');
            });

            // Fase 5 — prontuário, triagem, alergias, CID e medicamentos
            Route::get('encounters', [EncounterController::class, 'index'])->middleware('permission:prontuario.visualizar')->name('encounters.index');
            Route::post('encounters', [EncounterController::class, 'store'])->middleware('permission:prontuario.editar')->name('encounters.store');
            Route::get('encounters/{encounter}', [EncounterController::class, 'show'])->middleware('permission:prontuario.visualizar')->name('encounters.show');
            Route::put('encounters/{encounter}/draft', [EncounterController::class, 'draft'])->middleware('permission:prontuario.editar')->name('encounters.draft');
            Route::post('encounters/{encounter}/finalize', [EncounterController::class, 'finalize'])->middleware('permission:prontuario.finalizar')->name('encounters.finalize');
            Route::post('encounters/{encounter}/addenda', [EncounterController::class, 'addendum'])->middleware('permission:prontuario.editar')->name('encounters.addendum');
            Route::get('triages', [ClinicalController::class, 'triages'])->middleware('permission:triagem.visualizar|prontuario.visualizar')->name('triages.index');
            Route::post('triages', [ClinicalController::class, 'storeTriage'])->middleware('permission:triagem.registrar')->name('triages.store');
            Route::get('patients/{patient}/allergies', [ClinicalController::class, 'allergies'])->middleware('permission:prontuario.visualizar|triagem.visualizar')->name('allergies.index');
            Route::post('patients/{patient}/allergies', [ClinicalController::class, 'storeAllergy'])->middleware('permission:prontuario.editar|triagem.registrar')->name('allergies.store');
            Route::get('cid', [ClinicalController::class, 'cid'])->middleware('permission:prontuario.editar|prontuario.visualizar')->name('cid.search');
            Route::get('medications', [ClinicalController::class, 'medications'])->middleware('permission:prontuario.editar|medicamento.gerenciar')->name('medications.search');

            // Fase 6 — documentos médicos (permissão por tipo verificada no controller)
            Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
            Route::post('documents', [DocumentController::class, 'store'])->middleware('throttle:60,1')->name('documents.store');
            Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
            Route::post('documents/{document}/cancel', [DocumentController::class, 'cancel'])->name('documents.cancel');
            Route::get('documents/{document}/pdf', [DocumentController::class, 'pdf'])->middleware('throttle:30,1')->name('documents.pdf');

            // Fase 7 — financeiro (valores em centavos)
            Route::get('receivables', [FinanceController::class, 'receivables'])->middleware('permission:financeiro.visualizar|caixa.operar')->name('receivables.index');
            Route::post('receivables', [FinanceController::class, 'storeReceivable'])->middleware('permission:financeiro.editar|caixa.operar')->name('receivables.store');
            Route::post('receivables/{receivable}/receive', [FinanceController::class, 'receive'])->middleware('permission:caixa.operar|financeiro.editar')->name('receivables.receive');
            Route::post('receivables/{receivable}/cancel', [FinanceController::class, 'cancelReceivable'])->middleware('permission:financeiro.editar')->name('receivables.cancel');
            Route::get('payables', [FinanceController::class, 'payables'])->middleware('permission:financeiro.visualizar')->name('payables.index');
            Route::post('payables', [FinanceController::class, 'storePayable'])->middleware('permission:financeiro.editar')->name('payables.store');
            Route::post('payables/{payable}/pay', [FinanceController::class, 'pay'])->middleware('permission:financeiro.editar')->name('payables.pay');
            Route::post('payables/{payable}/cancel', [FinanceController::class, 'cancelPayable'])->middleware('permission:financeiro.editar')->name('payables.cancel');
            Route::post('transactions/{transaction}/reverse', [FinanceController::class, 'reverse'])->middleware('permission:pagamento.estornar')->name('transactions.reverse');
            Route::get('cash-sessions/current', [FinanceController::class, 'currentSession'])->middleware('permission:caixa.operar')->name('cash.current');
            Route::post('cash-sessions', [FinanceController::class, 'openSession'])->middleware('permission:caixa.operar')->name('cash.open');
            Route::post('cash-sessions/{session}/movements', [FinanceController::class, 'movement'])->middleware('permission:caixa.operar')->name('cash.movement');
            Route::post('cash-sessions/{session}/close', [FinanceController::class, 'closeSession'])->middleware('permission:caixa.operar')->name('cash.close');
            Route::post('cash-sessions/{session}/review', [FinanceController::class, 'reviewSession'])->middleware('permission:caixa.conferir')->name('cash.review');
            Route::get('finance/summary', [FinanceController::class, 'summary'])->middleware('permission:financeiro.visualizar|relatorio.financeiro')->name('finance.summary');

            // Fase 8 — cobranças online
            Route::post('receivables/{receivable}/charges', [ChargeController::class, 'store'])->middleware(['permission:pagamento.cobrar', 'throttle:30,1'])->name('charges.store');
            Route::get('charges/{charge}', [ChargeController::class, 'show'])->middleware('permission:pagamento.visualizar|pagamento.cobrar')->name('charges.show');
            Route::post('charges/{charge}/sync', [ChargeController::class, 'sync'])->middleware(['permission:pagamento.visualizar|pagamento.cobrar', 'throttle:30,1'])->name('charges.sync');
            Route::post('charges/{charge}/cancel', [ChargeController::class, 'cancel'])->middleware('permission:pagamento.cobrar')->name('charges.cancel');
            Route::post('charges/{charge}/refund', [ChargeController::class, 'refund'])->middleware('permission:pagamento.estornar')->name('charges.refund');

            Route::get('queue', [QueueController::class, 'index'])->middleware('permission:fila.visualizar')->name('queue.index');
            Route::middleware('permission:fila.gerenciar')->group(function () {
                Route::post('queue/tickets', [QueueController::class, 'issue'])->name('queue.issue');
                Route::post('queue/call-next', [QueueController::class, 'callNext'])->name('queue.call_next');
                Route::post('queue/tickets/{ticket}/call', [QueueController::class, 'call'])->name('queue.call');
                Route::post('queue/tickets/{ticket}/recall', [QueueController::class, 'recall'])->name('queue.recall');
                Route::post('queue/tickets/{ticket}/{action}', [QueueController::class, 'action'])->whereIn('action', ['start', 'finish', 'skip', 'transfer'])->name('queue.action');
            });
        });

        // ------------------------------------------------ Plataforma (SaaS)
        Route::prefix('platform')->name('platform.')->middleware(['platform', '2fa.enrolled'])->group(function () {
            Route::get('health', [PlatformHealthController::class, 'health'])->name('health');
            Route::get('metrics', [PlatformHealthController::class, 'metrics'])->name('metrics');
            Route::get('companies', [PlatformCompanyController::class, 'index'])->name('companies.index');
            Route::post('companies', [PlatformCompanyController::class, 'store'])->name('companies.store');
            Route::get('companies/{company}', [PlatformCompanyController::class, 'show'])->name('companies.show');
            Route::patch('companies/{company}', [PlatformCompanyController::class, 'update'])->name('companies.update');
            Route::get('plans', [PlatformPlanController::class, 'index'])->name('plans.index');
            Route::post('plans', [PlatformPlanController::class, 'store'])->name('plans.store');
            Route::patch('plans/{plan}', [PlatformPlanController::class, 'update'])->name('plans.update');
        });
    });
});
