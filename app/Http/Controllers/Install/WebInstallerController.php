<?php

namespace App\Http\Controllers\Install;

use App\Core\Health\HealthChecker;
use App\Core\Install\Installer;
use App\Core\Security\PasswordRules;
use App\Core\Validation\Cnpj;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;
use Throwable;

/**
 * Instalador web para hospedagem compartilhada (cPanel sem SSH).
 *
 * Segurança:
 *  - só existe enquanto não houver storage/app/installed.lock (depois: 404);
 *  - exige INSTALL_TOKEN (definido no .env) em toda requisição;
 *  - roda sem sessão/cookies (as tabelas ainda não existem) — o token faz o papel de CSRF.
 */
class WebInstallerController extends Controller
{
    public function __construct(private readonly Installer $installer) {}

    public function show(Request $request): View
    {
        $this->guard();

        return $this->page('token');
    }

    public function handle(Request $request, HealthChecker $health): View
    {
        $this->guard();

        if (! $this->validToken((string) $request->input('token'))) {
            usleep(random_int(300_000, 800_000)); // desestimula força bruta

            return $this->page('token', ['token' => 'Token de instalação inválido.']);
        }

        $token = (string) $request->input('token');
        $keyOk = $this->installer->ensureAppKey();
        $requirements = $this->installer->requirements();
        $database = $this->installer->checkDatabase();
        $ready = $keyOk && $this->installer->requirementsMet() && $database['ok'];

        if ($request->input('step') !== 'install' || ! $ready) {
            return $this->page('checks', [], compact('token', 'requirements', 'database', 'ready', 'keyOk'));
        }

        $rules = [
            'legal_name' => ['required', 'string', 'max:200'],
            'trade_name' => ['required', 'string', 'max:200'],
            'document' => ['required', new Cnpj],
            'admin_name' => ['required', 'string', 'max:150'],
            'admin_email' => ['required', 'email', 'max:190', 'different:super_email'],
            'admin_password' => ['required', 'confirmed', PasswordRules::default()],
            'super_name' => ['required', 'string', 'max:150'],
            'super_email' => ['required', 'email', 'max:190'],
            'super_password' => ['required', 'confirmed', PasswordRules::default()],
        ];

        $validator = Validator::make($request->all(), $rules, [], [
            'legal_name' => 'razão social', 'trade_name' => 'nome fantasia', 'document' => 'CNPJ',
            'admin_name' => 'nome do administrador', 'admin_email' => 'e-mail do administrador', 'admin_password' => 'senha do administrador',
            'super_name' => 'nome do Super Admin', 'super_email' => 'e-mail do Super Admin', 'super_password' => 'senha do Super Admin',
        ]);

        if ($validator->fails()) {
            return $this->page('checks', $validator->errors()->toArray(), compact('token', 'requirements', 'database', 'ready', 'keyOk') + ['old' => $request->except(['token', 'admin_password', 'admin_password_confirmation', 'super_password', 'super_password_confirmation'])]);
        }

        $v = $validator->validated();

        try {
            $this->installer->prepareDatabase();

            if (! $this->installer->hasSuperAdmin()) {
                $this->installer->createSuperAdmin(['name' => $v['super_name'], 'email' => $v['super_email'], 'password' => $v['super_password']]);
            }

            if (! $this->installer->hasCompany()) {
                $this->installer->createFirstClinic([
                    'legal_name' => $v['legal_name'], 'trade_name' => $v['trade_name'], 'document' => preg_replace('/\D/', '', $v['document']),
                    'admin_name' => $v['admin_name'], 'admin_email' => $v['admin_email'], 'admin_password' => $v['admin_password'],
                ]);
            }

            $this->installer->markInstalled();
        } catch (Throwable $e) {
            Log::error('Falha no instalador web', ['exception' => $e]);

            return $this->page('checks', ['install' => ['Falha na instalação: '.$e->getMessage()]], compact('token', 'requirements', 'database', 'ready', 'keyOk'));
        }

        return $this->page('done', [], ['checks' => $health->run()]);
    }

    private function guard(): void
    {
        abort_if($this->installer->isInstalled(), 404);
        abort_if(blank(config('aivexa.install_token')), 404);
    }

    private function validToken(string $token): bool
    {
        return strlen((string) config('aivexa.install_token')) >= 16 && hash_equals((string) config('aivexa.install_token'), $token);
    }

    private function page(string $step, array $errors = [], array $data = []): View
    {
        $bag = new ViewErrorBag;
        $bag->put('default', new MessageBag($errors));

        return view('install.index', ['step' => $step, 'errors' => $bag, 'old' => [], ...$data]);
    }
}
