<?php

namespace Tests\Feature\Security;

use App\Core\Security\FileScanner;
use App\Modules\Ai\Models\AiMedia;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatement;
use App\Modules\Banking\Models\BankStatementLine;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionInvoice;
use App\Modules\Documents\Models\PatientFile;
use App\Modules\Platform\Models\Company;
use App\Modules\Reports\Models\DoctorClosing;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;
use Tests\Feature\Scheduling\SchedulingSetup;
use Tests\TestCase;

/** Fase 17 — varredura de arquivos, retenção LGPD, central de segurança, proxies e isolamento das rotas novas. */
class AdvancedSecurityTest extends TestCase
{
    use SchedulingSetup;

    private const EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpScheduling();
    }

    private function tenant(Closure $fn): mixed
    {
        return $this->context()->runFor($this->company()->id, $fn);
    }

    private function upload(string $name, string $content)
    {
        return $this->actingAs($this->clinic['admin'])->post(route('patient_files.store', $this->patient()), ['file' => UploadedFile::fake()->createWithContent($name, $content), 'category' => 'other', 'title' => $name]);
    }

    public function test_uploads_are_scanned_and_dangerous_files_blocked(): void
    {
        $this->upload('ok.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<<>>\n%%EOF")->assertSessionHas('success');
        $this->assertSame('basic', $this->tenant(fn () => PatientFile::query()->sole()->scan_status));

        $this->upload('js.pdf', "%PDF-1.4\n1 0 obj<</OpenAction<</S/JavaScript/JS(app.alert(1))>>>>endobj\n%%EOF")->assertSessionHas('error', fn ($m) => str_contains($m, 'PDF com JavaScript'));
        $this->upload('esc.pdf', "%PDF-1.4\n1 0 obj<</S/Ja#76aScript>>endobj\n%%EOF")->assertSessionHas('error'); // nome escapado
        $this->upload('launch.pdf', "%PDF-1.4\n1 0 obj<</S/Launch/F(cmd.exe)>>endobj\n%%EOF")->assertSessionHas('error', fn ($m) => str_contains($m, 'abrir programa'));
        $this->upload('poly.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==').'<?php system($_GET[1]); ?>')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'imagem com código'));
        $this->upload('eicar.pdf', "%PDF-1.4\n".self::EICAR."\n%%EOF")->assertSessionHas('error', fn ($m) => str_contains($m, 'EICAR'));
        $this->assertSame(1, $this->tenant(fn () => PatientFile::query()->count()));
        $this->assertSame(5, DB::table('audit_logs')->where('action', 'security.file_blocked')->count());

        // Extrato bancário com EICAR também é barrado.
        $account = $this->tenant(fn () => BankAccount::create(['name' => 'Conta']));
        $this->actingAs($this->clinic['admin'])->post(route('bank.accounts.import', $account), ['file' => UploadedFile::fake()->createWithContent('x.csv', "Data;Valor\n01/10/2026;10,00\n".self::EICAR)])
            ->assertSessionHas('error');

        // ClamAV indisponível: "fail closed" recusa; "fail open" aceita só com verificações básicas.
        config(['aivexa.security.scanner.driver' => 'clamav', 'aivexa.security.scanner.clamav_socket' => '/tmp/nao-existe-clamd.sock', 'aivexa.security.scanner.fail_closed' => true]);
        $this->upload('b.pdf', "%PDF-1.4\n%%EOF")->assertSessionHas('error', fn ($m) => str_contains($m, 'Antivírus indisponível'));
        config(['aivexa.security.scanner.fail_closed' => false]);
        $this->assertSame(FileScanner::BASIC, app(FileScanner::class)->assertSafe("%PDF-1.4\n%%EOF", 'application/pdf'));
    }

    public function test_retention_policy_only_touches_operational_data(): void
    {
        $cid = $this->company()->id;
        $old = now()->subDays(400);
        $pat = $this->patient();
        DB::table('staff_notifications')->insert(['id' => (string) Str::ulid(), 'company_id' => $cid, 'type' => 'x', 'title' => 'Velho', 'level' => 'info', 'created_at' => $old]);
        DB::table('staff_notifications')->insert(['id' => (string) Str::ulid(), 'company_id' => $cid, 'type' => 'x', 'title' => 'Novo', 'level' => 'info', 'created_at' => now()]);
        DB::table('ai_requests')->insert(['id' => (string) Str::ulid(), 'company_id' => $cid, 'provider' => 'mock', 'model' => 'mock', 'created_at' => $old]);
        DB::table('payment_webhook_events')->insert(['id' => (string) Str::ulid(), 'company_id' => $cid, 'gateway_id' => null, 'provider' => 'asaas', 'provider_event_id' => 'e1', 'payload' => '{"a":1}', 'status' => 'processed', 'received_at' => $old]);
        $encounters = DB::table('encounters')->count();

        // Padrão: WhatsApp mantido.
        $this->tenant(fn () => DB::table('messages')->insert(['id' => (string) Str::ulid(), 'company_id' => $cid, 'patient_id' => $pat->id, 'channel' => 'whatsapp', 'direction' => 'in',
            'purpose' => 'inbound', 'recipient' => '5511999990000', 'body' => 'texto antigo', 'status' => 'received', 'attempts' => 0, 'created_at' => $old, 'updated_at' => $old]));
        $this->artisan('aivexa:retention:run')->assertSuccessful();
        $this->assertSame(['Novo'], DB::table('staff_notifications')->where('company_id', $cid)->pluck('title')->all());
        $this->assertSame(0, DB::table('ai_requests')->where('company_id', $cid)->count());
        $this->assertNull(DB::table('payment_webhook_events')->where('provider_event_id', 'e1')->value('payload'));
        $this->assertSame('texto antigo', DB::table('messages')->where('company_id', $cid)->value('body'));
        $this->assertSame($encounters, DB::table('encounters')->count());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'retention.applied')->exists());

        // Clínica define prazo para o texto do WhatsApp (validação: mínimo 30 dias nos demais).
        $admin = $this->clinic['admin'];
        $this->actingAs($admin)->put(route('security.retention'), ['notifications' => 10, 'ai_logs' => 365, 'whatsapp_text' => 365, 'webhook_payloads' => 180])->assertSessionHasErrors('notifications');
        $this->actingAs($admin)->put(route('security.retention'), ['notifications' => 180, 'ai_logs' => 365, 'whatsapp_text' => 365, 'webhook_payloads' => 180])->assertSessionHas('success');
        $this->artisan('aivexa:retention:run')->assertSuccessful();
        $this->assertSame('[texto removido pela política de retenção]', DB::table('messages')->where('company_id', $cid)->value('body'));
    }

    public function test_security_center_and_cloudflare_ranges(): void
    {
        $admin = $this->clinic['admin'];
        $this->post('/login', ['email' => $admin->email, 'password' => 'errada']);
        $this->actingAs($admin)->get(route('security.index'))->assertOk()->assertSee('Central de segurança')
            ->assertSee('sem verificação em duas etapas')->assertSee($admin->name)->assertSee('auth.login.failed');
        $this->actingAs($this->userWithRole($this->company(), 'recepcao'))->get(route('security.index'))->assertForbidden();

        $ranges = require base_path('config/proxies.php');
        $this->assertTrue(IpUtils::checkIp('104.16.1.1', $ranges['cloudflare']));
        $this->assertTrue(IpUtils::checkIp('2606:4700::1', $ranges['cloudflare']));
        $this->assertFalse(IpUtils::checkIp('200.200.200.200', $ranges['cloudflare']));
    }

    public function test_new_modules_never_leak_between_clinics(): void
    {
        $cid = $this->company()->id;
        $account = $this->tenant(fn () => BankAccount::create(['name' => 'Conta Alfa']));
        $statement = $this->tenant(fn () => BankStatement::create(['bank_account_id' => $account->id, 'source' => 'csv', 'lines_total' => 1]));
        $line = $this->tenant(fn () => BankStatementLine::create(['bank_account_id' => $account->id, 'statement_id' => $statement->id, 'dedupe_key' => 'k', 'posted_on' => '2026-10-01', 'amount_cents' => 100, 'description' => 'x']));
        $media = $this->tenant(fn () => AiMedia::create(['source' => 'upload', 'kind' => 'image', 'mime' => 'image/png', 'path' => 'companies/'.$cid.'/x.png']));
        $closing = $this->tenant(fn () => DoctorClosing::create(['doctor_id' => $this->doctor->id, 'period' => '2026-09', 'data' => ['a' => 1], 'hash' => str_repeat('0', 64), 'closed_by' => $this->clinic['admin']->id, 'closed_at' => now()]));
        $sub = Subscription::query()->where('company_id', $cid)->firstOrFail();
        $invoice = SubscriptionInvoice::create(['company_id' => $cid, 'subscription_id' => $sub->id, 'number' => 'AVX-T-1', 'kind' => 'manual', 'description' => 'x', 'amount_cents' => 100, 'due_date' => '2026-10-10']);

        $other = $this->createClinic('Clínica Invasora')['admin'];
        foreach ([route('bank.accounts.show', $account), route('bank.lines.show', $line), route('ai.media.show', $media), route('ai.media.file', $media),
            route('closings.show', $closing), route('closings.pdf', $closing), route('billing.pay', $invoice)] as $url) {
            $status = $this->actingAs($other)->get($url)->getStatusCode();
            $this->assertContains($status, [403, 404], "Vazamento entre clínicas em {$url} (HTTP {$status})");
        }
        foreach ([route('bank.lines.match', $line), route('bank.lines.ignore', $line), route('ai.media.verify', $media), route('closings.respond', $closing), route('billing.check', $invoice)] as $url) {
            $status = $this->actingAs($other)->post($url, ['transactions' => ['x'], 'reason' => 'abc', 'action' => 'confirm'])->getStatusCode();
            $this->assertContains($status, [403, 404], "Ação entre clínicas em {$url} (HTTP {$status})");
        }
        $this->assertSame('pending', $this->tenant(fn () => $line->fresh()->status));
        $this->assertSame(Company::STATUS_ACTIVE, Company::query()->find($cid)->status);
    }
}
