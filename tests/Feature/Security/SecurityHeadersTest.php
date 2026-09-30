<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_security_headers_are_present(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("script-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_authenticated_responses_are_not_cacheable(): void
    {
        ['admin' => $admin] = $this->createClinic();

        $this->assertStringContainsString('no-store', $this->actingAs($admin)->get('/')->headers->get('Cache-Control'));
    }

    public function test_web_forms_carry_csrf_token(): void
    {
        // O middleware de CSRF é ignorado pelo Laravel em testes; garantimos que os formulários enviam o token.
        $this->get('/login')->assertSee('name="_token"', false);
    }

    public function test_sql_injection_attempts_are_treated_as_data(): void
    {
        ['admin' => $admin] = $this->createClinic();

        $this->api($admin)->getJson("/api/v1/users?search=' OR 1=1 --")->assertOk()->assertJsonCount(0, 'data');
        $this->api($admin)->getJson('/api/v1/audit-logs?action=%25')->assertOk();
    }

    public function test_html_is_escaped_in_views(): void
    {
        ['admin' => $admin, 'branch' => $branch] = $this->createClinic();
        $this->api($admin)->patchJson("/api/v1/branches/{$branch->id}", ['name' => '<script>alert(1)</script>'])->assertOk();

        $this->actingAs($admin)->get(route('branches.index'))
            ->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }
}
