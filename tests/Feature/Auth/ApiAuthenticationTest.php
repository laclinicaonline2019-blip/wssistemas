<?php

namespace Tests\Feature\Auth;

use App\Modules\Platform\Models\Company;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    public function test_issues_token_with_valid_credentials_and_audits_login(): void
    {
        ['admin' => $admin] = $this->createClinic();

        $this->postJson('/api/v1/auth/token', ['email' => $admin->email, 'password' => self::PASSWORD, 'device_name' => 't'])
            ->assertCreated()
            ->assertJsonStructure(['access_token', 'expires_at', 'user' => ['id', 'email']]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $admin->id, 'result' => 'success']);
        $this->assertNotNull($admin->fresh()->last_login_at);
    }

    public function test_wrong_password_and_unknown_email_return_the_same_neutral_error(): void
    {
        ['admin' => $admin] = $this->createClinic();

        $wrong = $this->postJson('/api/v1/auth/token', ['email' => $admin->email, 'password' => 'errada', 'device_name' => 't'])->assertUnauthorized();
        $unknown = $this->postJson('/api/v1/auth/token', ['email' => 'nao@existe.test', 'password' => 'errada', 'device_name' => 't'])->assertUnauthorized();

        $this->assertSame($wrong->json('message'), $unknown->json('message'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login.failed', 'user_id' => $admin->id, 'result' => 'failure']);
    }

    public function test_account_is_locked_after_repeated_failures(): void
    {
        config(['aivexa.security.lockout_threshold' => 3, 'aivexa.security.login_rate_per_minute' => 100]);
        ['admin' => $admin] = $this->createClinic();

        foreach (range(1, 3) as $_) {
            $this->postJson('/api/v1/auth/token', ['email' => $admin->email, 'password' => 'errada', 'device_name' => 't']);
        }

        $this->assertTrue($admin->fresh()->isLocked());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.account.locked', 'user_id' => $admin->id]);

        // Mesmo com a senha correta, a conta segue bloqueada.
        $this->postJson('/api/v1/auth/token', ['email' => $admin->email, 'password' => self::PASSWORD, 'device_name' => 't'])
            ->assertUnauthorized()->assertJsonPath('code', 'auth_locked');

        $this->travel(16)->minutes();
        $this->postJson('/api/v1/auth/token', ['email' => $admin->email, 'password' => self::PASSWORD, 'device_name' => 't'])->assertCreated();
    }

    public function test_login_is_rate_limited_per_email_and_ip(): void
    {
        config(['aivexa.security.login_rate_per_minute' => 2]);
        ['admin' => $admin] = $this->createClinic();

        $this->postJson('/api/v1/auth/token', ['email' => $admin->email, 'password' => 'x', 'device_name' => 't']);
        $this->postJson('/api/v1/auth/token', ['email' => $admin->email, 'password' => 'x', 'device_name' => 't']);
        $this->postJson('/api/v1/auth/token', ['email' => $admin->email, 'password' => self::PASSWORD, 'device_name' => 't'])
            ->assertStatus(429)->assertJsonPath('code', 'auth_throttled');
    }

    public function test_blocked_user_and_suspended_company_cannot_authenticate(): void
    {
        ['company' => $company, 'admin' => $admin] = $this->createClinic();
        $recepcao = $this->userWithRole($company, 'recepcao', attrs: ['status' => 'blocked']);

        $this->postJson('/api/v1/auth/token', ['email' => $recepcao->email, 'password' => self::PASSWORD, 'device_name' => 't'])->assertUnauthorized();

        $this->context()->runAsSystem(fn () => $company->update(['status' => Company::STATUS_SUSPENDED]));
        $this->postJson('/api/v1/auth/token', ['email' => $admin->email, 'password' => self::PASSWORD, 'device_name' => 't'])
            ->assertUnauthorized()->assertJsonPath('code', 'auth_company_inactive');
    }

    public function test_tokens_expire(): void
    {
        config(['aivexa.security.api_token_ttl_minutes' => 30]);
        ['admin' => $admin] = $this->createClinic();
        $token = $this->apiToken($admin);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->travel(31)->minutes();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        ['admin' => $admin] = $this->createClinic();
        $token = $this->apiToken($admin);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_two_factor_flow_with_replay_protection_and_single_use_recovery_codes(): void
    {
        ['admin' => $admin] = $this->createClinic();
        $g = new Google2FA;

        $token = $this->apiToken($admin);
        $secret = $this->withToken($token)->postJson('/api/v1/auth/two-factor/setup', ['password' => self::PASSWORD])
            ->assertOk()->json('secret');

        $this->withToken($token)->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])->assertUnprocessable();

        $codes = $this->withToken($token)->postJson('/api/v1/auth/two-factor/confirm', ['code' => $g->getCurrentOtp($secret)])
            ->assertOk()->json('recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.2fa.enabled', 'user_id' => $admin->id]);

        // Agora a emissão de token exige o segundo fator.
        $this->postJson('/api/v1/auth/token', ['email' => $admin->email, 'password' => self::PASSWORD, 'device_name' => 't'])
            ->assertUnauthorized()->assertJsonPath('code', 'two_factor_required');

        // O mesmo código TOTP não pode ser reutilizado (replay). Usa a janela seguinte
        // (tolerância ±1), pois o código da janela atual já foi consumido na confirmação.
        $code = $g->oathTotp($secret, $g->getTimestamp() + 1);
        $this->postJson('/api/v1/auth/token', ['email' => $admin->email, 'password' => self::PASSWORD, 'device_name' => 't', 'code' => $code])->assertCreated();
        $this->postJson('/api/v1/auth/token', ['email' => $admin->email, 'password' => self::PASSWORD, 'device_name' => 't', 'code' => $code])
            ->assertUnauthorized()->assertJsonPath('code', 'two_factor_invalid');

        // Código de recuperação: uso único.
        $this->postJson('/api/v1/auth/token', ['email' => $admin->email, 'password' => self::PASSWORD, 'device_name' => 't', 'recovery_code' => $codes[0]])->assertCreated();
        $this->postJson('/api/v1/auth/token', ['email' => $admin->email, 'password' => self::PASSWORD, 'device_name' => 't', 'recovery_code' => $codes[0]])->assertUnauthorized();

        // Segredos nunca em texto claro no banco.
        $raw = DB::table('users')->where('id', $admin->id)->value('two_factor_secret');
        $this->assertNotSame($secret, $raw);
        $this->assertStringNotContainsString($secret, (string) $raw);
    }

    public function test_passwords_are_hashed_with_argon2id(): void
    {
        ['admin' => $admin] = $this->createClinic();

        $this->assertStringStartsWith('$argon2id$', DB::table('users')->where('id', $admin->id)->value('password'));
    }
}
