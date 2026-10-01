<?php

namespace App\Modules\Documents\Http\Controllers\Web;

use App\Core\Support\Format;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Platform\Models\Company;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Validação pública de documento pelo código/QR Code (farmácias, empresas, escolas).
 * Mostra só o necessário para conferir o papel em mãos: tipo, número, data, médico,
 * situação e iniciais do paciente. Itens só para receitas (a farmácia confere a prescrição).
 */
class DocumentValidationController extends Controller
{
    public function form(): View
    {
        return view('documents.validate', ['doc' => null, 'searched' => false]);
    }

    public function show(Request $request, string $code, DocumentService $documents, TenantContext $context): View
    {
        $doc = $documents->findByCode($code);

        $data = $doc ? $context->runAsSystem(fn () => [
            'type' => $doc->typeLabel(),
            'number' => $doc->displayNumber(),
            'code' => $doc->formattedCode(),
            'issued_at' => $doc->issued_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
            'valid_until' => $doc->valid_until?->format('d/m/Y'),
            'status' => $doc->status,
            'cancelled_at' => $doc->cancelled_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
            'intact' => $documents->verify($doc),
            'clinic' => Company::query()->find($doc->company_id)?->trade_name,
            'doctor' => ($doc->content['doctor']['name'] ?? '—').' — '.($doc->content['doctor']['registration'] ?? ''),
            'patient' => Format::initials($doc->content['patient']['name'] ?? ''),
            'items' => in_array($doc->type, ['prescription', 'special_prescription'], true)
                ? collect($doc->content['items'] ?? [])->map(fn ($i) => trim(($i['name'] ?? '').' — '.($i['quantity'] ?? '')))->all() : [],
            'days' => $doc->type === 'certificate' ? ($doc->content['days'] ?? null) : null,
        ]) : null;

        return view('documents.validate', ['doc' => $data, 'searched' => true]);
    }

    public function lookup(Request $request)
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:20']])['code'];

        return redirect()->route('documents.validate', strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $code)));
    }
}
