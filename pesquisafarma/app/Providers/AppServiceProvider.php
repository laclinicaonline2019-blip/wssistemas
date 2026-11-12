<?php

namespace App\Providers;

use App\Services\WhatsApp\EvolutionGateway;
use App\Services\WhatsApp\MetaGateway;
use App\Services\WhatsApp\MockGateway;
use App\Services\WhatsApp\WhatsAppGateway;
use App\Services\WhatsApp\ZApiGateway;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WhatsAppGateway::class, fn () => match (config('pesquisafarma.whatsapp_driver')) {
            'zapi' => new ZApiGateway,
            'evolution' => new EvolutionGateway,
            'meta' => new MetaGateway,
            default => new MockGateway,
        });
    }

    public function boot(): void
    {
        Paginator::defaultView('partials.pagination');
        Gate::define('admin', fn ($u) => $u->isAdmin());
        Gate::define('staff', fn ($u) => $u->isStaff());
        Gate::define('pharmacy', fn ($u) => $u->isPharmacy());
    }
}
