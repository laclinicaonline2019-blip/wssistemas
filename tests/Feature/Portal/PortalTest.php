<?php

namespace Tests\Feature\Portal;

use App\Modules\Documents\Models\PatientFile;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Company;
use App\Modules\Portal\Mail\PortalAccessMail;
use App\Modules\Portal\Models\PatientAccount;
use App\Modules\Scheduling\Models\Appointment;
use Database\Seeders\ClinicalCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Clinical\ClinicalSetup;
use Tests\TestCase;

class PortalTest extends TestCase
{
    use ClinicalSetup;

    private const CPF = '52998224725';

    private const PORTAL_PASSWORD = 'Portal#Seguro2026';

    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpClinical();
        $this->tenant(fn () => $this->pat->forceFill(['cpf' => self::CPF, 'email' => 'paciente@example.test', 'whatsapp' => '11999990000'])->save());
    }

    private function tenant(\Closure $fn): mixed
    {
        return $this->context()->runFor($this->company()->id, $fn);
    }

    private function slug(?Company $company = null): string
    {
        return ($company ?? $this->company())->fresh()->slug;
    }

    private function url(string $route, array $params = [], ?Company $company = null): string
    {
        return route($route, ['clinic' => $this->slug($company)] + $params);
    }

    /** Recepção gera o link; devolve a URL de ativação. */
    private function inviteLink(?Patient $patient = null, array $extra = []): string
    {
        $reception = $this->userWithRole($this->company(), 'recepcao');
        $this->actingAs($reception)->post(route('patients.portal.link', $patient ?? $this->pat), $extra)->assertSessionHas('portal_link');

        return session('portal_link')['url'];
    }

    private function activate(): PatientAccount
    {
        $url = $this->inviteLink();
        auth()->guard('web')->logout();
        $this->post($url, ['password' => self::PORTAL_PASSWORD, 'password_confirmation' => self::PORTAL_PASSWORD, 'terms' => '1'])->assertRedirect($this->url('portal.home'));

        return $this->tenant(fn () => PatientAccount::query()->where('patient_id', $this->pat->id)->firstOrFail());
    }

    private function loginPatient(string $login = self::CPF): void
    {
        $this->post($this->url('portal.login.attempt'), ['login' => $login, 'password' => self::PORTAL_PASSWORD])->assertRedirect($this->url('portal.home'));
    }

    public function test_invitation_activation_login_and_token_rules(): void
    {
        Mail::fake();
        $url = $this->inviteLink(null, ['send_email' => '1']);
        Mail::assertSent(PortalAccessMail::class, fn ($m) => $m->hasTo('paciente@example.test') && $m->purpose === 'activation' && $m->url === $url);

        // Token só existe no link: no banco fica o hash.
        $token = basename($url);
        $this->assertSame(hash('sha256', $token), DB::table('patient_account_tokens')->value('token_hash'));
        $this->assertSame(0, DB::table('patient_account_tokens')->where('token_hash', $token)->count());
        $this->assertSame('invited', DB::table('patient_accounts')->value('status'));

        auth()->guard('web')->logout();
        $this->get($url)->assertOk()->assertSee('Ative seu acesso');
        $this->post($url, ['password' => 'fraca', 'password_confirmation' => 'fraca', 'terms' => '1'])->assertSessionHasErrors('password');
        $this->post($url, ['password' => self::PORTAL_PASSWORD, 'password_confirmation' => self::PORTAL_PASSWORD])->assertSessionHasErrors('terms');
        $this->post($url, ['password' => self::PORTAL_PASSWORD, 'password_confirmation' => self::PORTAL_PASSWORD, 'terms' => '1'])->assertRedirect($this->url('portal.home'));
        $this->get($this->url('portal.home'))->assertOk()->assertSee('Próximas consultas');

        // Link de uso único.
        $this->post($this->url('portal.logout'))->assertRedirect($this->url('portal.login'));
        $this->get($url)->assertOk()->assertSee('Link inválido');
        $this->get($this->url('portal.home'))->assertRedirect($this->url('portal.login'));

        // Login por CPF (com máscara) e por e-mail; senha errada → mensagem neutra.
        $this->post($this->url('portal.login.attempt'), ['login' => '529.982.247-25', 'password' => 'errada!!'])->assertSessionHasErrors(['login' => 'CPF/e-mail ou senha inválidos.']);
        $this->post($this->url('portal.login.attempt'), ['login' => '111.444.777-35', 'password' => 'qualquer!'])->assertSessionHasErrors(['login' => 'CPF/e-mail ou senha inválidos.']);
        $this->loginPatient('529.982.247-25');
        $this->post($this->url('portal.logout'));
        $this->loginPatient('PACIENTE@example.test');
        // Nova requisição em processo novo (guard sem usuário em memória): a sessão recarrega a conta pelo provider.
        $this->app['auth']->forgetGuards();
        $this->get($this->url('portal.home'))->assertOk()->assertSee('Próximas consultas');
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'portal.login')->where('actor_type', 'patient')->count());
    }

    public function test_lockout_block_anonymization_and_disabled_portal(): void
    {
        config(['aivexa.security.lockout_threshold' => 3, 'aivexa.security.login_rate_per_minute' => 50]);
        $this->activate();
        $this->post($this->url('portal.logout'));

        foreach (range(1, 3) as $i) {
            $this->post($this->url('portal.login.attempt'), ['login' => self::CPF, 'password' => 'errada'.$i]);
        }
        $this->post($this->url('portal.login.attempt'), ['login' => self::CPF, 'password' => self::PORTAL_PASSWORD])->assertSessionHasErrors('login');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'portal.account_locked')->count());

        // Desbloqueio pela clínica + bloqueio manual.
        $this->tenant(fn () => PatientAccount::query()->update(['locked_until' => null]));
        $this->loginPatient();
        $reception = $this->userWithRole($this->company(), 'recepcao');
        $this->actingAs($reception)->post(route('patients.portal.block', $this->pat))->assertSessionHas('success');
        $this->get($this->url('portal.home'))->assertRedirect($this->url('portal.login')); // sessão do paciente cai
        $this->post($this->url('portal.login.attempt'), ['login' => self::CPF, 'password' => self::PORTAL_PASSWORD])->assertSessionHasErrors('login');

        // Portal desativado pela clínica → 404.
        $this->tenant(fn () => Company::query()->whereKey($this->company()->id)->update(['settings' => json_encode(['portal' => ['enabled' => false]])]));
        $this->get($this->url('portal.login'))->assertNotFound();
        $this->get(route('portal.login', ['clinic' => 'nao-existe']))->assertNotFound();

        // Anonimização bloqueia o acesso; paciente anonimizado não recebe link.
        $this->tenant(fn () => Company::query()->whereKey($this->company()->id)->update(['settings' => json_encode([])]));
        $this->actingAs($reception)->post(route('patients.portal.block', $this->pat)); // desbloqueia
        $this->api($this->clinic['admin'])->postJson("/api/v1/patients/{$this->pat->id}/anonymize", ['reason' => 'Pedido do titular (LGPD)', 'confirm' => true])->assertOk();
        $this->assertSame(['blocked', null], [DB::table('patient_accounts')->value('status'), DB::table('patient_accounts')->value('email')]);
        $this->actingAs($reception)->post(route('patients.portal.link', $this->pat))->assertSessionHas('error');
    }

    public function test_patient_sees_only_own_data_documents_files_and_payments(): void
    {
        $this->context()->runAsSystem(fn () => (new ClinicalCatalogSeeder)->run($this->context()));
        $docs = $this->api($this->doctorUser)->postJson('/api/v1/documents', ['type' => 'prescription', 'patient_id' => $this->pat->id, 'branch_id' => $this->branch()->id,
            'items' => [['name' => 'Paracetamol 750 mg', 'posology' => '6/6h', 'quantity' => '1 cx'], ['name' => 'Morfina', 'control_type' => 'A1', 'posology' => 'se dor', 'quantity' => '10', 'notification_number' => 'SP-1']]])
            ->assertCreated()->json('data');
        $byType = collect($docs)->keyBy('type');

        $other = $this->patient('Outro Paciente', ['cpf' => '11144477735']);
        $otherDoc = $this->api($this->doctorUser)->postJson('/api/v1/documents', ['type' => 'exam_request', 'patient_id' => $other->id, 'branch_id' => $this->branch()->id, 'exams' => ['TSH']])->json('data.0.id');
        $otherAppt = $this->api($this->clinic['admin'])->postJson('/api/v1/appointments', $this->bookPayload($other, '09:30'))->json('data.id');

        Storage::fake('local');
        [$shared, $hidden] = $this->tenant(function () {
            $make = fn (string $title, bool $visible) => (new PatientFile(['patient_id' => $this->pat->id, 'category' => 'exam', 'title' => $title, 'original_name' => $title.'.pdf',
                'mime' => 'application/pdf', 'size_bytes' => 10, 'disk' => 'local', 'path' => 'p/'.$title.'.pdf', 'sha256' => str_repeat('a', 64), 'uploaded_by' => $this->clinic['admin']->id]))->forceFill(['visible_to_patient' => $visible]);
            $a = $make('Laudo liberado', true);
            $a->save();
            $b = $make('Laudo interno', false);
            $b->save();
            Storage::disk('local')->put('p/Laudo liberado.pdf', '%PDF-1.4 teste');
            Storage::disk('local')->put('p/Laudo interno.pdf', '%PDF-1.4 teste');

            return [$a, $b];
        });

        // Conta e recebimento do paciente.
        $this->api($this->clinic['admin'])->postJson("/api/v1/appointments/{$this->appointmentId}/arrive")->assertCreated();
        $receivable = $this->tenant(fn () => Receivable::query()->where('appointment_id', $this->appointmentId)->firstOrFail());
        $txn = $this->api($this->clinic['admin'])->postJson("/api/v1/receivables/{$receivable->id}/receive", ['method' => 'pix', 'amount_cents' => 25000])->assertCreated()->json('data.transaction.id');

        $this->activate();
        $this->get($this->url('portal.documents'))->assertOk()->assertSee('Receita')->assertDontSee('Registro de notificação')->assertSee('Laudo liberado')->assertDontSee('Laudo interno');
        $pdf = $this->get($this->url('portal.documents.pdf', ['document' => $byType['prescription']['id']]))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->get($this->url('portal.documents.pdf', ['document' => $byType['notification_record']['id']]))->assertNotFound();
        $this->get($this->url('portal.documents.pdf', ['document' => $otherDoc]))->assertNotFound();
        $this->get($this->url('portal.files.download', ['file' => $shared->id]))->assertOk();
        $this->get($this->url('portal.files.download', ['file' => $hidden->id]))->assertNotFound();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'portal.document_downloaded')->where('actor_type', 'patient')->count());

        $this->get($this->url('portal.payments'))->assertOk()->assertSee('R$ 250,00')->assertSee('recibo');
        $this->get($this->url('portal.receipt', ['transaction' => $txn]))->assertOk()->assertSee('RECIBO');
        $this->get($this->url('portal.appointments'))->assertOk()->assertDontSee('Outro Paciente');
        $this->post($this->url('portal.appointments.cancel', ['appointment' => $otherAppt]))->assertNotFound();
        foreach (['portal.home', 'portal.doctors', 'portal.profile', 'portal.book'] as $page) {
            $this->get($this->url($page))->assertOk();
        }
        $this->get($this->url('portal.profile'))->assertSee('***.982.247-**')->assertDontSee(self::CPF);

        // Clínica liberando/retirando arquivo do portal.
        $this->actingAs($this->clinic['admin'])->patch(route('patient_files.share', $hidden))->assertSessionHas('success');
        $this->get($this->url('portal.files.download', ['file' => $hidden->id]))->assertOk();
    }

    public function test_online_booking_window_confirmation_and_cancellation(): void
    {
        $this->activate();
        $this->get($this->url('portal.book', ['doctor_id' => $this->doctor->id]))->assertOk()->assertSee('09:00')->assertDontSee('value="'.$this->at('08:00').'"', false);

        // Antecedência mínima (2 h): 07:00 não pode, e encaixe nunca é aceito pelo portal.
        $payload = ['doctor_id' => $this->doctor->id, 'branch_id' => $this->branch()->id, 'service_id' => $this->service->id, 'payer_type' => 'private'];
        $this->post($this->url('portal.book.store'), $payload + ['starts_at' => $this->at('07:30')])->assertSessionHas('error');
        $this->post($this->url('portal.book.store'), $payload + ['starts_at' => $this->at('08:00')])->assertSessionHas('error'); // horário ocupado
        $this->post($this->url('portal.book.store'), $payload + ['starts_at' => $this->at('09:00'), 'idempotency_key' => 'k1'])->assertRedirect($this->url('portal.appointments'));
        $this->post($this->url('portal.book.store'), $payload + ['starts_at' => $this->at('09:00'), 'idempotency_key' => 'k1'])->assertRedirect(); // clique duplo

        $appt = $this->tenant(fn () => Appointment::query()->where('channel', 'portal')->sole());
        $this->assertSame([false, 'scheduled', $this->pat->id], [$appt->is_overbook, $appt->status, $appt->patient_id]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'portal.appointment_booked')->where('actor_type', 'patient')->count());

        // Confirma; cancelar com menos de 24 h é recusado.
        $this->post($this->url('portal.appointments.confirm', ['appointment' => $appt->id]))->assertSessionHas('success');
        $this->post($this->url('portal.appointments.cancel', ['appointment' => $appt->id]))->assertSessionHas('error');

        // Próxima semana: cancela pelo portal.
        $this->post($this->url('portal.book.store'), $payload + ['starts_at' => $this->at('08:30', '2026-10-12')])->assertRedirect();
        $next = $this->tenant(fn () => Appointment::query()->where('channel', 'portal')->where('status', 'scheduled')->sole());
        $this->post($this->url('portal.appointments.cancel', ['appointment' => $next->id]))->assertSessionHas('success');
        $next->refresh();
        $this->assertSame(['cancelled', null], [$next->status, $next->cancelled_by]);
        $this->assertSame('patient', DB::table('audit_logs')->where('action', 'appointment.cancelled')->where('auditable_id', $next->id)->value('actor_type'));

        // Agendamento online desativado.
        $this->tenant(fn () => Company::query()->whereKey($this->company()->id)->update(['settings' => json_encode(['portal' => ['booking_enabled' => false]])]));
        $this->post($this->url('portal.book.store'), $payload + ['starts_at' => $this->at('09:30')])->assertSessionHas('error');
    }

    public function test_isolation_between_clinics_and_coexisting_staff_session(): void
    {
        $this->activate();
        $beta = $this->createClinic('Clínica Beta');

        // Logado na clínica A, o portal da clínica B trata como visitante; o mesmo CPF não entra na B.
        $this->get($this->url('portal.home', [], $beta['company']))->assertRedirect($this->url('portal.login', [], $beta['company']));
        $this->post($this->url('portal.login.attempt', [], $beta['company']), ['login' => self::CPF, 'password' => self::PORTAL_PASSWORD])->assertSessionHasErrors('login');

        // Equipe logada no mesmo navegador: a ação do portal é do paciente, não do usuário da clínica.
        $this->actingAs($this->clinic['admin']);
        $this->get(route('patients.show', $this->pat))->assertOk()->assertSee('Portal do paciente');
        $this->get($this->url('portal.documents'))->assertOk();
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'like', 'portal.%')->where('actor_type', 'patient')->whereNotNull('user_id')->count());

        // Esqueci a senha: e-mail só quando CPF + nascimento conferem; resposta sempre neutra.
        Mail::fake();
        $this->post($this->url('portal.forgot.store'), ['cpf' => self::CPF, 'birth_date' => '1990-01-01'])->assertRedirect($this->url('portal.login'));
        Mail::assertNothingSent();
        $this->post($this->url('portal.forgot.store'), ['cpf' => self::CPF, 'birth_date' => '1985-01-01'])->assertRedirect($this->url('portal.login'));
        Mail::assertSent(PortalAccessMail::class, fn ($m) => $m->purpose === 'reset');

        // Configurações da clínica.
        $this->actingAs($this->clinic['admin'])->get(route('company.edit'))->assertOk()->assertSee('Portal do paciente');
        $this->actingAs($this->clinic['admin'])->put(route('company.update'), ['settings' => ['portal' => ['enabled' => '1', 'booking_enabled' => '0', 'booking_min_notice_hours' => 4, 'booking_max_days' => 30, 'cancel_min_hours' => 12]]])
            ->assertSessionHas('success');
        $this->assertSame(12, $this->company()->fresh()->setting('portal.cancel_min_hours'));
    }
}
