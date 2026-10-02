<?php

namespace App\Modules\Payments\Http\Controllers\Web;

use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\PaymentCharge;
use App\Modules\Platform\Models\Company;
use Illuminate\View\View;

/** Página pública da cobrança (link enviado ao paciente): valor, PIX copia-e-cola/QR e botão para pagar no gateway. */
class PublicPaymentController extends Controller
{
    public function __invoke(string $token, TenantContext $context, DocumentService $documents): View
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        $data = $context->runAsSystem(function () use ($token) {
            $charge = PaymentCharge::query()->withoutGlobalScopes()->where('public_token', $token)->first();
            abort_unless($charge, 404);
            $receivable = Receivable::query()->withoutGlobalScopes()->find($charge->receivable_id);
            $patient = $charge->patient_id ? Patient::query()->withoutGlobalScopes()->find($charge->patient_id) : null;

            return ['charge' => $charge, 'description' => $receivable?->description, 'company' => Company::query()->find($charge->company_id),
                'patient' => $patient ? Format::initials($patient->displayName()) : null];
        });

        $charge = $data['charge'];
        $qr = $charge->pix_qr_image ? 'data:image/png;base64,'.$charge->pix_qr_image : ($charge->pix_payload ? $documents->qrDataUri($charge->pix_payload) : null);

        return view('payments.public', $data + ['qr' => $qr]);
    }
}
