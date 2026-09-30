<?php

namespace App\Modules\Queue\Http\Controllers\Web;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\Branch;
use App\Modules\Queue\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Painel de chamadas para TV/monitor. Acesso por token secreto da unidade (sem
 * login na TV). Exibe apenas senha, nome reduzido (configurável), sala e médico.
 */
class PanelController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly QueueService $queue,
    ) {}

    public function show(string $token): View
    {
        $branch = $this->branch($token);

        return view('queue.panel', ['branch' => $branch, 'token' => $token]);
    }

    public function state(string $token): JsonResponse
    {
        $branch = $this->branch($token);

        return response()->json($this->context->runFor($branch->company_id, fn () => $this->queue->panelState($branch)))
            ->header('Cache-Control', 'no-store');
    }

    private function branch(string $token): Branch
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        $key = 'panel:'.request()->ip();
        abort_if(RateLimiter::tooManyAttempts($key, 120), 429);
        RateLimiter::hit($key, 60);

        $branchId = Cache::remember(self::cacheKey($token), 300, fn () => $this->context->runAsSystem(
            fn () => Branch::query()->where('status', 'active')->where('settings->panel->token', $token)->value('id')
        ));

        $branch = $branchId ? $this->context->runAsSystem(fn () => Branch::query()->whereKey($branchId)->where('status', 'active')->first()) : null;

        // Confere o token de novo (tempo constante) — protege contra cache desatualizado após rotação.
        abort_unless($branch !== null && hash_equals((string) data_get($branch->settings, 'panel.token', ''), $token), 404);

        return $branch;
    }

    public static function cacheKey(string $token): string
    {
        return 'panel-token:'.hash('sha256', $token);
    }
}
