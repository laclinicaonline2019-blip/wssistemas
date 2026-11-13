<?php

namespace App\Http\Controllers\Admin;

use App\Core\Support\BusinessRule;
use App\Http\Controllers\Controller;
use App\Services\Update\PackageInspector;
use App\Services\Update\UpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Atualizações pelo painel do proprietário (sem SSH). Exige login + 2FA do proprietário e reconfirmação da senha. */
class UpdateController extends Controller
{
    public function __construct(private readonly UpdateService $updates) {}

    public function index(): View
    {
        return view('admin.updates', [
            'version' => config('app.version'),
            'packages' => $this->updates->packages(),
            'history' => array_slice($this->updates->history(), 0, 15),
            'active' => $this->updates->activeRun(),
            'writable' => $this->updates->canWriteCode(),
            'zip' => class_exists(\ZipArchive::class),
            'uploadMax' => (string) ini_get('upload_max_filesize'),
            'postMax' => (string) ini_get('post_max_size'),
            'inboxPath' => 'storage/app/updates/pacotes',
            'signedRequired' => (string) config('wsf.update.public_key') !== '',
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $request->validate(['pacote' => ['required', 'file', 'mimes:zip', 'max:512000']]);
        $file = $request->file('pacote');
        $name = preg_replace('/[^A-Za-z0-9._\-]/', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'pacote';
        $file->move($this->updates->inbox(), mb_substr($name, 0, 100).'.zip');

        return back()->with('ok', 'Pacote enviado. Clique em "Analisar".');
    }

    public function analyze(Request $request, PackageInspector $inspector): View|RedirectResponse
    {
        $name = (string) $request->input('pacote');
        try {
            $info = $inspector->inspect($this->updates->packagePath($name), (string) config('app.version'));
        } catch (BusinessRule $e) {
            return back()->with('error', $e->getMessage());
        }

        return view('admin.update-analyze', ['name' => $name, 'info' => $info, 'version' => config('app.version')]);
    }

    public function start(Request $request): RedirectResponse
    {
        $request->validate(['pacote' => ['required', 'string'], 'password' => ['required', 'current_password'], 'ciente' => ['accepted']],
            ['ciente.accepted' => 'Confirme que leu os avisos.', 'password.current_password' => 'Senha incorreta.']);
        $run = $this->updates->start((string) $request->input('pacote'), $request->boolean('sem_assinatura'), $request->user()->id);

        return redirect()->route('admin.updates.run', $run['id']);
    }

    public function run(string $id): View
    {
        return view('admin.update-run', ['run' => $this->updates->load($id), 'steps' => UpdateService::STEPS]);
    }

    /** Uma etapa por chamada (o navegador chama em sequência). */
    public function step(string $id): JsonResponse
    {
        $s = $this->updates->runNext($id);

        return response()->json(['status' => $s['status'], 'busy' => $s['busy'] ?? false, 'done' => $s['done'], 'next' => $s['step'],
            'log' => array_slice($s['log'], -12), 'error' => $s['error'] ?? null, 'to' => $s['to']]);
    }

    public function revert(Request $request, string $id): RedirectResponse
    {
        $request->validate(['modo' => ['required', 'in:codigo,banco'], 'password' => ['required', 'current_password']], ['password.current_password' => 'Senha incorreta.']);
        $s = $this->updates->revert($id, (string) $request->input('modo'), $request->user()->id);

        return redirect()->route('admin.updates')->with('ok', 'Sistema revertido para a versão '.$s['from'].'.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        @unlink($this->updates->packagePath((string) $request->input('pacote')));

        return back()->with('ok', 'Pacote removido.');
    }
}
