<?php

namespace App\Providers;

use App\Core\Tenancy\TenantContext;
use App\Models\PlatformSetting;
use App\Services\Install\WebInstaller;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);

        // Antes da instalação: sessão/cache em memória/arquivos (as tabelas ainda não existem) e, se o .env
        // ainda não tem APP_KEY, uma chave TEMPORÁRIA só em memória — o Laravel exige uma chave até para montar
        // um redirecionamento. Nada persistente é criptografado com ela: a chave definitiva é gerada e gravada
        // no .env pelo instalador (etapa "Chave de segurança").
        if (! $this->app->environment('testing') && ! WebInstaller::lockedStatic()) {
            config(['session.driver' => 'array', 'cache.default' => 'file']);
            if (empty(config('app.key'))) {
                config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
            }
        }
    }

    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        $this->loadPlatformSettings();

        $proxies = trim((string) env('TRUSTED_PROXIES', ''));
        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }

        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(10)->by(mb_strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(30)->by($r->ip()),
        ]);
        RateLimiter::for('install', fn (Request $r) => Limit::perMinute(40)->by('install|'.$r->ip()));
        RateLimiter::for('install-code', fn (Request $r) => Limit::perMinutes(10, 10)->by('install-code|'.$r->ip()));
        RateLimiter::for('signup', fn (Request $r) => Limit::perHour(10)->by($r->ip()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(240)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(600)->by($r->ip()));
        RateLimiter::for('assistant', fn (Request $r) => Limit::perMinute(40)->by($r->user()?->id ?: $r->ip()));
    }

    /** Políticas editáveis no painel do proprietário sobrepõem os padrões do .env. */
    private function loadPlatformSettings(): void
    {
        if (! file_exists(storage_path('app/installed')) && ! $this->app->environment('testing')) {
            return;
        }
        try {
            foreach (PlatformSetting::query()->get() as $s) {
                config(['wsf.'.$s->key => json_decode((string) $s->value, true)]);
            }
        } catch (\Throwable) {
            // banco ainda não disponível (instalação/migração)
        }
    }
}
