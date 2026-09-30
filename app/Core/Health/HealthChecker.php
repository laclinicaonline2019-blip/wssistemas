<?php

namespace App\Core\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** Verificações de saúde reutilizadas pelo endpoint, painel e instalador. */
class HealthChecker
{
    /** @return array<string, array{ok: bool, detail: string, ms: float}> */
    public function run(): array
    {
        return [
            'database' => $this->measure(function () {
                DB::select('select 1');

                return DB::connection()->getDriverName().' · '.DB::connection()->getDatabaseName();
            }),
            'migrations' => $this->measure(function () {
                $ran = DB::table('migrations')->count();
                $files = count(glob(database_path('migrations/*.php')));

                if ($ran < $files) {
                    throw new \RuntimeException("{$ran}/{$files} migrations executadas");
                }

                return "{$ran} migrations";
            }),
            'cache' => $this->measure(function () {
                $key = 'health:'.Str::random(8);
                Cache::put($key, 'ok', 10);
                $ok = Cache::pull($key) === 'ok';

                if (! $ok) {
                    throw new \RuntimeException('leitura/escrita falhou');
                }

                return config('cache.default');
            }),
            'storage' => $this->measure(function () {
                $disk = Storage::disk(config('filesystems.default'));
                $path = '.health/'.Str::random(8);
                $disk->put($path, 'ok');
                $disk->delete($path);

                return config('filesystems.default').' (privado)';
            }),
            'audit' => $this->measure(function () {
                $triggers = $this->auditTriggersActive();
                $scopes = DB::table('audit_chain_heads')->count();

                return ($triggers ? 'bloqueio no banco ativo' : 'sem trigger (hospedagem compartilhada) — protegida pela cadeia HMAC')
                    ." · {$scopes} cadeias";
            }),
            'queue' => $this->measure(function () {
                $driver = config('queue.default');
                $failed = DB::table('failed_jobs')->count();

                if ($driver === 'database') {
                    $pending = DB::table('jobs')->count();
                    $stale = DB::table('jobs')->where('available_at', '<', now()->subMinutes(10)->timestamp)->whereNull('reserved_at')->count();

                    if ($stale > 0) {
                        throw new \RuntimeException("{$stale} jobs aguardando há mais de 10 min — verifique o cron (schedule:run)");
                    }

                    return "{$driver} · pendentes: {$pending} · falhas: {$failed}";
                }

                return "{$driver} · falhas: {$failed}";
            }),
        ];
    }

    public function auditTriggersActive(): bool
    {
        return match (DB::getDriverName()) {
            'pgsql' => DB::table('pg_trigger')->where('tgname', 'audit_logs_immutable')->exists(),
            'mysql', 'mariadb' => DB::table('information_schema.triggers')
                ->where('trigger_schema', DB::getDatabaseName())->where('event_object_table', 'audit_logs')->count() >= 2,
            default => false,
        };
    }

    public function healthy(array $results): bool
    {
        return collect($results)->every(fn ($r) => $r['ok']);
    }

    private function measure(callable $check): array
    {
        $start = microtime(true);

        try {
            $detail = (string) $check();
            $ok = true;
        } catch (Throwable $e) {
            report($e);
            $detail = app()->isProduction() ? 'falha (ver logs)' : Str::limit($e->getMessage(), 200);
            $ok = false;
        }

        return ['ok' => $ok, 'detail' => $detail, 'ms' => round((microtime(true) - $start) * 1000, 1)];
    }
}
