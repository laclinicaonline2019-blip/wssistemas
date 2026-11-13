<?php

namespace App\Http\Controllers;

use App\Core\Security\PasswordRules;
use App\Core\Support\BusinessRule;
use App\Services\Install\InstallGuard;
use App\Services\Install\WebInstaller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Instalador web (sem terminal) — rotas FORA do grupo "web": não usa sessão, cookies criptografados
 * nem banco, então funciona mesmo com o .env sem APP_KEY. Proteções: HTTPS, código temporário lido no
 * servidor, sessão própria, CSRF próprio, limite de tentativas e bloqueio definitivo ao concluir.
 */
class InstallController extends Controller
{
    public const STEPS = [
        'requisitos' => 'Requisitos da hospedagem',
        'chaves' => 'Chave de segurança (APP_KEY)',
        'banco' => 'Banco de dados MySQL',
        'aplicacao' => 'Endereço e ambiente',
        'migracoes' => 'Tabelas do banco (migrações)',
        'proprietario' => 'Administrador principal',
        'concluir' => 'Concluir e bloquear o instalador',
    ];

    public function __construct(private readonly WebInstaller $installer, private readonly InstallGuard $guard) {}

    public function show(Request $request): Response
    {
        if ($r = $this->gate($request, false)) {
            return $r;
        }
        if (! $this->guard->session($request)) {
            $this->guard->ensureCode();

            return $this->view($request, 'install.code', ['codeFile' => $this->relative($this->guard->codeFile())]);
        }
        $done = (array) $this->guard->recall($request, 'done', []);
        $current = collect(array_keys(self::STEPS))->first(fn ($s) => ! in_array($s, $done, true)) ?? 'concluir';
        $step = (string) $request->query('etapa', $current);
        if (! array_key_exists($step, self::STEPS) || array_search($step, array_keys(self::STEPS)) > array_search($current, array_keys(self::STEPS))) {
            $step = $current;
        }

        $data = ['step' => $step, 'done' => $done, 'steps' => self::STEPS];
        try {
            $data += match ($step) {
                'requisitos' => ['checks' => $this->installer->requirements($request)],
                'chaves' => ['hasKey' => (bool) $this->installer->env()->get('APP_KEY')],
                'banco' => ['env' => $this->installer->env()->values()],
                'aplicacao' => ['url' => $this->installer->env()->get('APP_URL') ?: 'https://'.$request->getHost(), 'stage' => $this->installer->env()->get('APP_STAGE', 'homologation')],
                'migracoes' => ['status' => $this->installer->migrationStatus(), 'output' => $this->guard->recall($request, 'migrate_output')],
                'proprietario' => ['owner' => $this->installer->ownerEmail()],
                default => [],
            };
        } catch (BusinessRule|\Throwable $e) {
            $data['stepError'] = $e instanceof BusinessRule ? $e->getMessage() : 'Não foi possível ler o banco com a configuração atual: '.mb_substr($e->getMessage(), 0, 160);
        }

        return $this->view($request, 'install.step', $data);
    }

    public function code(Request $request): Response
    {
        if ($r = $this->gate($request, false)) {
            return $r;
        }
        $check = $this->guard->checkCode((string) $request->input('codigo'));
        if (! $check['ok']) {
            return $this->view($request, 'install.code', ['codeFile' => $this->relative($this->guard->codeFile()), 'error' => $check['message']], 422);
        }

        return $this->go('/instalar')->withCookie($this->guard->startSession($request));
    }

    public function step(Request $request, string $step): Response
    {
        if ($r = $this->gate($request, true)) {
            return $r;
        }
        try {
            match ($step) {
                'requisitos' => $this->doRequirements($request),
                'chaves' => $this->doKeys($request),
                'banco' => $this->doDatabase($request),
                'aplicacao' => $this->installer->saveApplication((string) $request->input('app_url'), (string) $request->input('stage')),
                'migracoes' => $this->doMigrations($request),
                'proprietario' => $this->doOwner($request),
                'concluir' => null,
                default => abort(404),
            };
        } catch (BusinessRule $e) {
            $this->guard->remember($request, 'flash', ['error' => $e->getMessage()]);

            return $this->go('/instalar?etapa='.$step);
        }

        if ($step === 'concluir') {
            $done = (array) $this->guard->recall($request, 'done', []);
            foreach (array_diff(array_keys(self::STEPS), ['concluir']) as $s) {
                if (! in_array($s, $done, true)) {
                    $this->guard->remember($request, 'flash', ['error' => 'Conclua a etapa "'.self::STEPS[$s].'" antes.']);

                    return $this->go('/instalar?etapa='.$s);
                }
            }
            $result = $this->installer->finish();

            return response()->view('install.done', $result)->withCookie(Cookie::create(InstallGuard::COOKIE, '', 1, '/instalar'));
        }

        $done = array_values(array_unique(array_merge((array) $this->guard->recall($request, 'done', []), [$step])));
        $this->guard->remember($request, 'done', $done);
        $this->guard->remember($request, 'flash', ['ok' => self::STEPS[$step].': concluído.']);

        return $this->go('/instalar');
    }

    // ------------------------------------------------------------------ etapas

    private function doRequirements(Request $request): void
    {
        $log = $this->installer->preparePaths();
        $blockers = $this->installer->blockers($this->installer->requirements($request));
        if ($blockers) {
            throw new BusinessRule('Resolva antes: '.implode('; ', array_map(fn ($b) => $b['item'], $blockers)).'.');
        }
        $this->guard->remember($request, 'paths_log', $log);
    }

    private function doKeys(Request $request): void
    {
        $generated = $this->installer->ensureKeys();
        $this->guard->remember($request, 'keys', $generated);
    }

    private function doDatabase(Request $request): void
    {
        $c = $this->installer->validateDbInput($request->only(['db_host', 'db_port', 'db_database', 'db_username', 'db_password', 'keep_password']));
        $info = $this->installer->testDatabase($c);
        $this->installer->saveDatabase($c);
        $this->guard->remember($request, 'db_info', $info);
    }

    private function doMigrations(Request $request): void
    {
        $this->guard->remember($request, 'migrate_output', $this->installer->migrate());
        if ($this->installer->migrationStatus()['pending']) {
            throw new BusinessRule('Ainda há migrações pendentes. Veja a saída abaixo e tente novamente.');
        }
    }

    private function doOwner(Request $request): void
    {
        if ($this->installer->ownerEmail()) {
            return; // já existe: dados preservados, nada é criado
        }
        $v = Validator::make($request->all(), ['name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'confirmed', PasswordRules::default()]]);
        if ($v->fails()) {
            throw new BusinessRule(implode(' ', $v->errors()->all()));
        }
        $this->installer->createOwner($request->input('name'), $request->input('email'), $request->input('password'));
    }

    // ------------------------------------------------------------------ apoio

    /** Bloqueio, HTTPS e (para POST) sessão + CSRF do instalador. */
    private function gate(Request $request, bool $post): ?Response
    {
        if ($this->installer->locked()) {
            abort(404);
        }
        if (! $this->installer->httpsOk($request)) {
            return response()->view('install.https', ['url' => 'https://'.$request->getHost().'/instalar'], 403);
        }
        if ($post && ! $this->guard->validPost($request)) {
            return $this->guard->session($request) ? response()->view('install.expired', [], 419) : $this->go('/instalar');
        }

        return null;
    }

    private function view(Request $request, string $view, array $data = [], int $status = 200): Response
    {
        $flash = $this->guard->recall($request, 'flash');
        if ($flash) {
            $this->guard->remember($request, 'flash', null);
        }

        return response()->view($view, $data + ['csrf' => $this->guard->csrf($request), 'flash' => $flash ?? [], 'version' => config('app.version')], $status)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    /** Redirecionamento relativo (não depende de APP_URL, que pode ainda não estar configurado). */
    private function go(string $path): RedirectResponse
    {
        return new RedirectResponse($path, 303);
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/');
    }
}
