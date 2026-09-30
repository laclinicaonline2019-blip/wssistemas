<?php

namespace Tests\Feature\Auth;

use App\Modules\Identity\Models\User;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class WebAuthenticationTest extends TestCase
{
    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Bem-vindo de volta');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_user_logs_in_and_out(): void
    {
        ['admin' => $admin] = $this->createClinic();

        $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($admin);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'user_id' => $admin->id]);
    }

    public function test_invalid_credentials_show_neutral_error(): void
    {
        ['admin' => $admin] = $this->createClinic();

        $this->from('/login')->post('/login', ['email' => $admin->email, 'password' => 'errada'])
            ->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_two_factor_challenge_is_required_when_enabled(): void
    {
        ['admin' => $admin] = $this->createClinic();
        $g = new Google2FA;
        $secret = $g->generateSecretKey(32);
        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();

        $this->get('/two-factor-challenge')->assertOk();
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['code' => $g->getCurrentOtp($secret)])->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_pending_two_factor_expires(): void
    {
        ['admin' => $admin] = $this->createClinic();
        $g = new Google2FA;
        $secret = $g->generateSecretKey(32);
        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD]);
        $this->travel(6)->minutes();

        $this->post('/two-factor-challenge', ['code' => $g->getCurrentOtp($secret)])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_must_change_password_forces_account_page_until_changed(): void
    {
        ['admin' => $admin] = $this->createClinic();
        $admin->forceFill(['must_change_password' => true])->save();

        $this->actingAs($admin)->get('/')->assertRedirect(route('account.security'));

        $this->actingAs($admin)->put(route('account.password'), [
            'current_password' => self::PASSWORD,
            'password' => 'Nova@Senha2026',
            'password_confirmation' => 'Nova@Senha2026',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($admin->fresh()->must_change_password);
        $this->actingAs($admin->fresh())->get('/')->assertOk();
    }

    public function test_weak_password_is_rejected(): void
    {
        ['admin' => $admin] = $this->createClinic();

        $this->actingAs($admin)->put(route('account.password'), [
            'current_password' => self::PASSWORD,
            'password' => 'fraca123',
            'password_confirmation' => 'fraca123',
        ])->assertSessionHasErrors('password');
    }

    public function test_company_can_require_two_factor_for_everyone(): void
    {
        ['company' => $company, 'admin' => $admin] = $this->createClinic();
        $this->context()->runAsSystem(fn () => $company->update(['settings' => ['security' => ['require_2fa' => true]]]));

        $this->actingAs($admin)->get('/')->assertRedirect(route('account.security'));
        $this->actingAs($admin)->getJson('/api/v1/branches')->assertForbidden()->assertJsonPath('code', 'two_factor_enrollment_required');
    }

    public function test_super_admin_is_sent_to_the_platform_panel(): void
    {
        $super = $this->context()->runAsSystem(function () {
            $u = new User(['name' => 'Root', 'email' => 'root@example.test', 'password' => self::PASSWORD]);
            $u->is_super_admin = true;
            $u->save();

            return $u;
        });

        $this->post('/login', ['email' => $super->email, 'password' => self::PASSWORD])->assertRedirect(route('platform.dashboard'));
        $this->get('/')->assertRedirect(route('platform.dashboard'));
    }
}
