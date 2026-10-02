<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\PatientFile;
use App\Modules\Patients\Models\Patient;
use App\Modules\Portal\Models\PatientAccount;
use App\Modules\Portal\Services\PortalAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Lado da clínica: liberar o portal ao paciente, gerar link, bloquear e compartilhar anexos. */
class PortalAccessWebController extends Controller
{
    public function __construct(private readonly PortalAccountService $accounts) {}

    public function link(Request $request, Patient $patient): RedirectResponse
    {
        $data = $request->validate(['email' => ['nullable', 'email:rfc', 'max:190'], 'send_email' => ['nullable', 'boolean']], [], ['email' => 'e-mail']);
        $link = $this->accounts->issueLink($request->user(), $patient, $data['email'] ?? null);

        if ($request->boolean('send_email')) {
            $this->accounts->sendLink($link['account'], $link['url'], $link['purpose']);
        }

        // O link aparece UMA vez (o token não fica gravado) para copiar ou enviar pelo WhatsApp.
        return back()->with('portal_link', ['url' => $link['url'], 'purpose' => $link['purpose'], 'expires_at' => $link['expires_at']->toIso8601String(), 'emailed' => $request->boolean('send_email')])
            ->with('success', $link['purpose'] === 'activation' ? 'Link de ativação do portal gerado.' : 'Link para redefinir a senha do portal gerado.');
    }

    public function block(Request $request, Patient $patient): RedirectResponse
    {
        $account = PatientAccount::query()->where('patient_id', $patient->id)->firstOrFail();
        $blocked = $account->status !== 'blocked';
        $this->accounts->setBlocked($account, $blocked);

        return back()->with('success', $blocked ? 'Acesso ao portal bloqueado.' : 'Acesso ao portal desbloqueado.');
    }

    public function shareFile(PatientFile $file): RedirectResponse
    {
        $file->forceFill(['visible_to_patient' => ! $file->visible_to_patient])->save();
        app(AuditLogger::class)->record($file->visible_to_patient ? 'portal.file_shared' : 'portal.file_unshared', $file);

        return back()->with('success', $file->visible_to_patient ? 'Arquivo liberado no portal do paciente.' : 'Arquivo retirado do portal do paciente.');
    }
}
