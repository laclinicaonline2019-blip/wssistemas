<?php

namespace App\Providers;

use App\Core\Tenancy\TenantContext;
use App\Models\PlatformSetting;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);

        // Antes da instalação as tabelas de sessão/cache ainda não existem: o instalador usa arquivos.
        if (! file_exists(storage_path('app/installed')) && ! $this->app->environment('testing')) {
            config(['session.driver' => 'file', 'cache.default' => 'file']);
        }
    }

    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        $this->loadPlatformSettings();

        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(10)->by(mb_strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(30)->by($r->ip()),
        ]);
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
