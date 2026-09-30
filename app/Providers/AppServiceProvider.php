<?php

namespace App\Providers;

use App\Core\Access\PermissionRegistry;
use App\Core\Access\PermissionService;
use App\Core\Install\Installer;
use App\Core\Tenancy\TenantContext;
use App\Modules\Identity\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // "scoped": uma instância por requisição/job (seguro para filas e Octane).
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(PermissionService::class);
        $this->app->singleton(PermissionRegistry::class);
    }

    public function boot(): void
    {
        // Índices seguros em MySQL/MariaDB antigos (limite de 767 bytes por chave).
        Schema::defaultStringLength(191);

        // Instalação sem SSH (cPanel): o .env chega sem APP_KEY. Gera a chave antes de
        // qualquer componente precisar de criptografia — somente na rota do instalador.
        if (! config('app.key') && ! $this->app->runningInConsole() && request()->is('instalar')) {
            $installer = $this->app->make(Installer::class);

            if (! $installer->isInstalled()) {
                $installer->ensureAppKey();
            }
        }

        // Em desenvolvimento/testes: detecta N+1 e atributos descartados silenciosamente.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        if ($this->app->isProduction() || config('aivexa.security.force_https')) {
            URL::forceScheme('https');
        }

        // Toda permissão do catálogo é verificada pelo RBAC do sistema.
        Gate::before(function (User $user, string $ability, array $arguments) {
            $registry = app(PermissionRegistry::class);

            if (! $registry->exists($ability)) {
                return null;
            }

            $branchId = $arguments[0] ?? null;

            return app(PermissionService::class)->userHas($user, $ability, is_object($branchId) ? $branchId->getKey() : $branchId);
        });

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
