<?php

use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\UtilityController;
use App\Modules\Ai\Http\Controllers\AiConfigWebController;
use App\Modules\Audit\Http\Controllers\Web\AuditWebController;
use App\Modules\Clinical\Http\Controllers\Platform\ClinicalCatalogController;
use App\Modules\Clinical\Http\Controllers\Web\ClinicalSupportController;
use App\Modules\Clinical\Http\Controllers\Web\DoctorWorkspaceController;
use App\Modules\Clinical\Http\Controllers\Web\EncounterWebController;
use App\Modules\Doctors\Http\Controllers\Web\DoctorWebController;
use App\Modules\Doctors\Http\Controllers\Web\SpecialtyWebController;
use App\Modules\Documents\Http\Controllers\Web\DocumentWebController;
use App\Modules\Documents\Http\Controllers\Web\PatientFileController;
use App\Modules\Finance\Http\Controllers\Web\CashWebController;
use App\Modules\Finance\Http\Controllers\Web\FinanceWebController;
use App\Modules\Finance\Http\Controllers\Web\PayableWebController;
use App\Modules\Finance\Http\Controllers\Web\ReceivableWebController;
use App\Modules\Finance\Http\Controllers\Web\TransactionWebController;
use App\Modules\Identity\Http\Controllers\Web\AccountController;
use App\Modules\Identity\Http\Controllers\Web\LoginController;
use App\Modules\Identity\Http\Controllers\Web\RoleWebController;
use App\Modules\Identity\Http\Controllers\Web\UserWebController;
use App\Modules\Insurance\Http\Controllers\Web\BatchWebController;
use App\Modules\Insurance\Http\Controllers\Web\GuideWebController;
use App\Modules\Insurance\Http\Controllers\Web\InsurerWebController;
use App\Modules\Messaging\Http\Controllers\ChannelWebController;
use App\Modules\Messaging\Http\Controllers\InboxWebController;
use App\Modules\Messaging\Http\Controllers\NotificationWebController;
use App\Modules\Organization\Http\Controllers\Web\BranchWebController;
use App\Modules\Patients\Http\Controllers\Web\PatientWebController;
use App\Modules\Payments\Http\Controllers\Web\ChargeWebController;
use App\Modules\Payments\Http\Controllers\Web\GatewayWebController;
use App\Modules\Payments\Http\Controllers\Web\SplitWebController;
use App\Modules\Platform\Http\Controllers\Web\CompanySettingsWebController;
use App\Modules\Platform\Http\Controllers\Web\PlatformWebController;
use App\Modules\Portal\Http\Controllers\PortalAccessWebController;
use App\Modules\Portal\Http\Controllers\PortalAuthController;
use App\Modules\Portal\Http\Controllers\PortalController;
use App\Modules\Portal\Http\Middleware\PortalAuthenticate;
use App\Modules\Portal\Http\Middleware\ResolvePortalTenant;
use App\Modules\Printing\Http\Controllers\PrintTestController;
use App\Modules\Queue\Http\Controllers\Web\QueueWebController;
use App\Modules\Scheduling\Http\Controllers\Web\AgendaWebController;
use App\Modules\Scheduling\Http\Controllers\Web\ScheduleConfigWebController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- Portal do paciente (Fase 10)
Route::prefix('portal/{clinic}')->middleware(ResolvePortalTenant::class)->name('portal.')->where(['clinic' => '[a-z0-9\-]+'])->group(function () {
    Route::get('entrar', [PortalAuthController::class, 'show'])->name('login');
    Route::post('entrar', [PortalAuthController::class, 'login'])->middleware('throttle:auth')->name('login.attempt');
    Route::get('acesso/{token}', [PortalAuthController::class, 'showActivate'])->middleware('throttle:30,1')->name('activate');
    Route::post('acesso/{token}', [PortalAuthController::class, 'activate'])->middleware('throttle:10,1')->name('activate.store');
    Route::get('esqueci-a-senha', [PortalAuthController::class, 'showForgot'])->name('forgot');
    Route::post('esqueci-a-senha', [PortalAuthController::class, 'forgot'])->middleware('throttle:5,1')->name('forgot.store');

    Route::middleware(PortalAuthenticate::class)->group(function () {
        Route::post('sair', [PortalAuthController::class, 'logout'])->name('logout');
        Route::get('/', [PortalController::class, 'home'])->name('home');
        Route::get('consultas', [PortalController::class, 'appointments'])->name('appointments');
        Route::post('consultas/{appointment}/cancelar', [PortalController::class, 'cancel'])->middleware('throttle:10,1')->name('appointments.cancel');
        Route::post('consultas/{appointment}/confirmar', [PortalController::class, 'confirm'])->name('appointments.confirm');
        Route::get('agendar', [PortalController::class, 'book'])->name('book');
        Route::post('agendar', [PortalController::class, 'storeBooking'])->middleware('throttle:10,1')->name('book.store');
        Route::get('documentos', [PortalController::class, 'documents'])->name('documents');
        Route::get('documentos/{document}/pdf', [PortalController::class, 'documentPdf'])->middleware('throttle:30,1')->name('documents.pdf');
        Route::get('arquivos/{file}', [PortalController::class, 'file'])->middleware('throttle:30,1')->name('files.download');
        Route::get('pagamentos', [PortalController::class, 'payments'])->name('payments');
        Route::get('pagamentos/recibo/{transaction}', [PortalController::class, 'receipt'])->name('receipt');
        Route::get('medicos', [PortalController::class, 'doctors'])->name('doctors');
        Route::get('meus-dados', [PortalController::class, 'profile'])->name('profile');
        Route::put('meus-dados/senha', [PortalController::class, 'password'])->middleware('throttle:10,1')->name('password');
    });
});

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
        Route::get('pacientes/busca', [AgendaWebController::class, 'patientLookup'])->middleware(['permission:agenda.criar|paciente.visualizar', 'throttle:60,1'])->name('patient_lookup');
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

    // Fase 6 — documentos médicos e anexos do paciente (permissão por tipo verificada no controller)
    Route::get('documentos', [DocumentWebController::class, 'index'])->name('documents.index');
    Route::get('documentos/novo', [DocumentWebController::class, 'create'])->name('documents.create');
    Route::post('documentos', [DocumentWebController::class, 'store'])->middleware('throttle:60,1')->name('documents.store');
    Route::get('documentos/grupo/{group}/imprimir', [DocumentWebController::class, 'printGroup'])->where('group', '[0-9A-Za-z]{26}')->name('documents.print_group');
    Route::get('documentos/{document}', [DocumentWebController::class, 'show'])->name('documents.show');
    Route::get('documentos/{document}/imprimir', [DocumentWebController::class, 'print'])->name('documents.print');
    Route::get('documentos/{document}/pdf', [DocumentWebController::class, 'pdf'])->middleware('throttle:30,1')->name('documents.pdf');
    Route::post('documentos/{document}/cancelar', [DocumentWebController::class, 'cancel'])->name('documents.cancel');
    Route::post('pacientes/{patient}/arquivos', [PatientFileController::class, 'store'])->middleware(['permission:documento.anexar', 'throttle:30,1'])->name('patient_files.store');
    Route::get('arquivos/{file}', [PatientFileController::class, 'download'])->middleware('permission:documento.visualizar|prontuario.visualizar')->name('patient_files.download');
    Route::patch('arquivos/{file}/arquivar', [PatientFileController::class, 'archive'])->middleware('permission:documento.anexar')->name('patient_files.archive');
    Route::patch('arquivos/{file}/portal', [PortalAccessWebController::class, 'shareFile'])->middleware('permission:documento.anexar')->name('patient_files.share');
    Route::post('pacientes/{patient}/portal/link', [PortalAccessWebController::class, 'link'])->middleware(['permission:paciente.portal', 'throttle:20,1'])->name('patients.portal.link');
    Route::post('pacientes/{patient}/portal/bloqueio', [PortalAccessWebController::class, 'block'])->middleware('permission:paciente.portal')->name('patients.portal.block');

    // Fase 7 — financeiro e caixa
    Route::get('financeiro', [FinanceWebController::class, 'overview'])->middleware('permission:financeiro.visualizar|relatorio.financeiro')->name('finance.overview');
    Route::get('financeiro/categorias', [FinanceWebController::class, 'categories'])->middleware('permission:financeiro.editar')->name('finance.categories');
    Route::post('financeiro/categorias', [FinanceWebController::class, 'storeCategory'])->middleware('permission:financeiro.editar')->name('finance.categories.store');
    Route::patch('financeiro/categorias/{category}', [FinanceWebController::class, 'toggleCategory'])->middleware('permission:financeiro.editar')->name('finance.categories.toggle');
    Route::get('contas-a-receber', [ReceivableWebController::class, 'index'])->middleware('permission:financeiro.visualizar|caixa.operar')->name('receivables.index');
    Route::get('contas-a-receber/nova', [ReceivableWebController::class, 'create'])->middleware('permission:financeiro.editar|caixa.operar')->name('receivables.create');
    Route::post('contas-a-receber', [ReceivableWebController::class, 'store'])->middleware('permission:financeiro.editar|caixa.operar')->name('receivables.store');
    Route::get('contas-a-receber/{receivable}', [ReceivableWebController::class, 'show'])->middleware('permission:financeiro.visualizar|caixa.operar')->name('receivables.show');
    Route::post('contas-a-receber/{receivable}/receber', [ReceivableWebController::class, 'receive'])->middleware(['permission:caixa.operar|financeiro.editar', 'throttle:60,1'])->name('receivables.receive');
    Route::post('contas-a-receber/{receivable}/cancelar', [ReceivableWebController::class, 'cancel'])->middleware('permission:financeiro.editar')->name('receivables.cancel');
    Route::get('contas-a-pagar', [PayableWebController::class, 'index'])->middleware('permission:financeiro.visualizar')->name('payables.index');
    Route::post('contas-a-pagar', [PayableWebController::class, 'store'])->middleware('permission:financeiro.editar')->name('payables.store');
    Route::get('contas-a-pagar/{payable}', [PayableWebController::class, 'show'])->middleware('permission:financeiro.visualizar')->name('payables.show');
    Route::post('contas-a-pagar/{payable}/pagar', [PayableWebController::class, 'pay'])->middleware('permission:financeiro.editar')->name('payables.pay');
    Route::post('contas-a-pagar/{payable}/cancelar', [PayableWebController::class, 'cancel'])->middleware('permission:financeiro.editar')->name('payables.cancel');
    Route::get('caixa', [CashWebController::class, 'index'])->middleware('permission:caixa.operar')->name('cash.index');
    Route::post('caixa/abrir', [CashWebController::class, 'open'])->middleware('permission:caixa.operar')->name('cash.open');
    Route::get('caixa/conferencia', [CashWebController::class, 'sessions'])->middleware('permission:caixa.conferir|financeiro.visualizar')->name('cash.sessions');
    Route::get('caixa/{session}', [CashWebController::class, 'show'])->name('cash.show');
    Route::get('caixa/{session}/imprimir', [CashWebController::class, 'print'])->name('cash.print');
    Route::post('caixa/{session}/movimento', [CashWebController::class, 'movement'])->middleware('permission:caixa.operar')->name('cash.movement');
    Route::post('caixa/{session}/fechar', [CashWebController::class, 'close'])->middleware('permission:caixa.operar')->name('cash.close');
    Route::post('caixa/{session}/conferir', [CashWebController::class, 'review'])->middleware('permission:caixa.conferir')->name('cash.review');
    Route::post('movimentacoes/{transaction}/estornar', [TransactionWebController::class, 'reverse'])->middleware('permission:pagamento.estornar')->name('transactions.reverse');
    Route::get('movimentacoes/{transaction}/recibo', [TransactionWebController::class, 'receipt'])->middleware('permission:caixa.operar|financeiro.visualizar')->name('transactions.receipt');

    // Fase 8 — pagamentos online, gateways e repasses
    Route::get('configuracoes/pagamentos', [GatewayWebController::class, 'index'])->middleware('permission:integracao.gerenciar')->name('gateways.index');
    Route::post('configuracoes/pagamentos', [GatewayWebController::class, 'store'])->middleware('permission:integracao.gerenciar')->name('gateways.store');
    Route::put('configuracoes/pagamentos/{gateway}', [GatewayWebController::class, 'update'])->middleware('permission:integracao.gerenciar')->name('gateways.update');
    Route::post('configuracoes/pagamentos/{gateway}/testar', [GatewayWebController::class, 'test'])->middleware(['permission:integracao.gerenciar', 'throttle:10,1'])->name('gateways.test');
    Route::post('configuracoes/pagamentos/{gateway}/novo-token', [GatewayWebController::class, 'rotate'])->middleware('permission:integracao.gerenciar')->name('gateways.rotate');
    Route::get('cobrancas', [ChargeWebController::class, 'index'])->middleware('permission:pagamento.visualizar')->name('charges.index');
    Route::post('contas-a-receber/{receivable}/cobrancas', [ChargeWebController::class, 'store'])->middleware(['permission:pagamento.cobrar', 'throttle:30,1'])->name('charges.store');
    Route::post('cobrancas/{charge}/consultar', [ChargeWebController::class, 'sync'])->middleware(['permission:pagamento.visualizar|pagamento.cobrar', 'throttle:30,1'])->name('charges.sync');
    Route::post('cobrancas/{charge}/cancelar', [ChargeWebController::class, 'cancel'])->middleware('permission:pagamento.cobrar')->name('charges.cancel');
    Route::post('cobrancas/{charge}/estornar', [ChargeWebController::class, 'refund'])->middleware('permission:pagamento.estornar')->name('charges.refund');
    Route::post('cobrancas/{charge}/simular', [ChargeWebController::class, 'simulate'])->middleware('permission:pagamento.cobrar')->name('charges.simulate');
    Route::get('financeiro/repasses', [SplitWebController::class, 'index'])->middleware('permission:financeiro.repasse')->name('splits.index');
    Route::post('financeiro/repasses/regras', [SplitWebController::class, 'storeRule'])->middleware('permission:financeiro.repasse')->name('splits.rules.store');
    Route::patch('financeiro/repasses/regras/{rule}', [SplitWebController::class, 'toggleRule'])->middleware('permission:financeiro.repasse')->name('splits.rules.toggle');
    Route::put('financeiro/repasses/medicos/{doctor}/carteira', [SplitWebController::class, 'wallet'])->middleware('permission:financeiro.repasse')->name('splits.wallet');
    Route::post('financeiro/repasses/medicos/{doctor}/fechar', [SplitWebController::class, 'settle'])->middleware('permission:financeiro.repasse')->name('splits.settle');

    // Fase 9 — convênios: cadastro, tabelas, autorizações, guias, lotes TISS e glosas
    Route::middleware('permission:convenio.visualizar|convenio.gerenciar')->group(function () {
        Route::get('convenios', [InsurerWebController::class, 'index'])->name('insurers.index');
        Route::get('convenios/{insurer}', [InsurerWebController::class, 'show'])->name('insurers.show');
        Route::get('convenios/tabelas/{table}', [InsurerWebController::class, 'showTable'])->name('insurers.tables.show');
    });
    Route::middleware('permission:convenio.gerenciar')->group(function () {
        Route::post('convenios', [InsurerWebController::class, 'store'])->name('insurers.store');
        Route::put('convenios/{insurer}', [InsurerWebController::class, 'update'])->name('insurers.update');
        Route::post('convenios/{insurer}/planos', [InsurerWebController::class, 'storePlan'])->name('insurers.plans.store');
        Route::patch('convenios/planos/{plan}', [InsurerWebController::class, 'togglePlan'])->name('insurers.plans.toggle');
        Route::put('convenios/{insurer}/medicos', [InsurerWebController::class, 'doctors'])->name('insurers.doctors');
        Route::post('convenios/{insurer}/tabelas', [InsurerWebController::class, 'storeTable'])->name('insurers.tables.store');
        Route::put('convenios/tabelas/{table}', [InsurerWebController::class, 'updateTable'])->name('insurers.tables.update');
        Route::post('convenios/tabelas/{table}/itens', [InsurerWebController::class, 'storeItem'])->name('insurers.tables.items.store');
        Route::delete('convenios/tabelas/{table}/itens/{item}', [InsurerWebController::class, 'destroyItem'])->name('insurers.tables.items.destroy');
        Route::get('procedimentos', [InsurerWebController::class, 'procedures'])->name('procedures.index');
        Route::post('procedimentos', [InsurerWebController::class, 'storeProcedure'])->name('procedures.store');
        Route::patch('procedimentos/{procedure}', [InsurerWebController::class, 'toggleProcedure'])->name('procedures.toggle');
        Route::post('procedimentos/importar', [InsurerWebController::class, 'importProcedures'])->middleware('throttle:10,1')->name('procedures.import');
    });
    Route::middleware('permission:convenio.autorizar|convenio.faturar')->group(function () {
        Route::get('autorizacoes', [GuideWebController::class, 'authorizations'])->name('authorizations.index');
        Route::post('autorizacoes', [GuideWebController::class, 'storeAuthorization'])->name('authorizations.store');
        Route::post('autorizacoes/{authorization}/resposta', [GuideWebController::class, 'decideAuthorization'])->name('authorizations.decide');
        Route::post('autorizacoes/{authorization}/cancelar', [GuideWebController::class, 'cancelAuthorization'])->name('authorizations.cancel');
    });
    Route::middleware('permission:convenio.faturar')->group(function () {
        Route::get('guias', [GuideWebController::class, 'index'])->name('guides.index');
        Route::get('guias/nova', [GuideWebController::class, 'create'])->name('guides.create');
        Route::post('guias', [GuideWebController::class, 'store'])->name('guides.store');
        Route::post('agenda/{appointment}/guia', [GuideWebController::class, 'fromAppointment'])->name('guides.from_appointment');
        Route::get('guias/{guide}', [GuideWebController::class, 'show'])->name('guides.show');
        Route::put('guias/{guide}', [GuideWebController::class, 'update'])->name('guides.update');
        Route::post('guias/{guide}/itens', [GuideWebController::class, 'addItem'])->name('guides.items.store');
        Route::delete('guias/{guide}/itens/{item}', [GuideWebController::class, 'removeItem'])->name('guides.items.destroy');
        Route::post('guias/{guide}/pronta', [GuideWebController::class, 'ready'])->name('guides.ready');
        Route::post('guias/{guide}/rascunho', [GuideWebController::class, 'draft'])->name('guides.draft');
        Route::post('guias/{guide}/cancelar', [GuideWebController::class, 'cancel'])->name('guides.cancel');
        Route::post('guias/{guide}/cobrar-paciente', [GuideWebController::class, 'chargePatient'])->name('guides.charge_patient');
        Route::get('guias/{guide}/imprimir', [GuideWebController::class, 'print'])->name('guides.print');
        Route::post('guias/{guide}/glosa', [BatchWebController::class, 'glosa'])->name('guides.glosa');
        Route::get('lotes', [BatchWebController::class, 'index'])->name('batches.index');
        Route::post('lotes', [BatchWebController::class, 'store'])->name('batches.store');
        Route::get('lotes/{batch}', [BatchWebController::class, 'show'])->name('batches.show');
        Route::delete('lotes/{batch}/guias/{guide}', [BatchWebController::class, 'removeGuide'])->name('batches.guides.destroy');
        Route::post('lotes/{batch}/fechar', [BatchWebController::class, 'close'])->name('batches.close');
        Route::get('lotes/{batch}/xml', [BatchWebController::class, 'xml'])->name('batches.xml');
        Route::post('lotes/{batch}/envio', [BatchWebController::class, 'sent'])->name('batches.sent');
        Route::post('lotes/{batch}/cancelar', [BatchWebController::class, 'cancel'])->name('batches.cancel');
        Route::post('lotes/{batch}/retorno', [BatchWebController::class, 'registerReturn'])->name('batches.return');
    });

    // Fase 11 — WhatsApp, mensagens automáticas e notificações
    Route::middleware('permission:integracao.gerenciar')->group(function () {
        Route::get('configuracoes/whatsapp', [ChannelWebController::class, 'index'])->name('messaging.settings');
        Route::put('configuracoes/whatsapp/canal', [ChannelWebController::class, 'saveChannel'])->name('messaging.channel.save');
        Route::post('configuracoes/whatsapp/novo-token', [ChannelWebController::class, 'rotate'])->name('messaging.channel.rotate');
        Route::put('configuracoes/whatsapp/automacoes', [ChannelWebController::class, 'saveAutomation'])->name('messaging.automation.save');
    });
    Route::middleware('permission:ia.conversas')->group(function () {
        Route::get('conversas', [InboxWebController::class, 'index'])->name('messaging.inbox');
        Route::get('conversas/{thread}', [InboxWebController::class, 'show'])->name('messaging.threads.show');
        Route::post('conversas/{thread}/responder', [InboxWebController::class, 'reply'])->middleware('throttle:30,1')->name('messaging.threads.reply');
        Route::post('conversas/{thread}/encerrar', [InboxWebController::class, 'close'])->name('messaging.threads.close');
        Route::post('conversas/{thread}/simular', [InboxWebController::class, 'simulate'])->name('messaging.threads.simulate');
        Route::post('conversas/{thread}/assumir', [InboxWebController::class, 'takeOver'])->name('messaging.threads.take_over');
        Route::post('conversas/{thread}/devolver-ia', [InboxWebController::class, 'release'])->name('messaging.threads.release');
    });

    // Fase 12 — recepcionista virtual (IA)
    Route::middleware('permission:ia.configurar')->group(function () {
        Route::get('atendimento-ia', [AiConfigWebController::class, 'index'])->name('ai.settings');
        Route::put('atendimento-ia', [AiConfigWebController::class, 'save'])->name('ai.save');
        Route::post('atendimento-ia/testar', [AiConfigWebController::class, 'test'])->middleware('throttle:6,1')->name('ai.test');
    });
    Route::get('notificacoes', [NotificationWebController::class, 'index'])->name('notifications.index');
    Route::get('notificacoes/{notification}', [NotificationWebController::class, 'open'])->name('notifications.open');
    Route::post('notificacoes/lidas', [NotificationWebController::class, 'readAll'])->name('notifications.read_all');

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
