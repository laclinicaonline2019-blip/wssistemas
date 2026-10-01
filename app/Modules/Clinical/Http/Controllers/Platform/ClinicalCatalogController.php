<?php

namespace App\Modules\Clinical\Http\Controllers\Platform;

use App\Core\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Modules\Clinical\Models\CidCode;
use App\Modules\Clinical\Models\Medication;
use App\Modules\Clinical\Services\CidService;
use App\Modules\Clinical\Services\MedicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Bases clínicas globais (Super Admin): importação da CID-10 oficial e de medicamentos. */
class ClinicalCatalogController extends Controller
{
    public function index(): View
    {
        return view('platform.clinical-catalog', [
            'cid' => ['total' => CidCode::query()->count(), 'sample' => CidCode::query()->where('is_sample', true)->count()],
            'medications' => ['total' => Medication::query()->whereNull('company_id')->count(), 'sample' => Medication::query()->whereNull('company_id')->where('is_sample', true)->count()],
        ]);
    }

    public function importCid(Request $request, CidService $service, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'], 'version' => ['required', 'string', 'max:20']]);
        $stats = $service->import($request->file('file')->getRealPath(), $request->input('version'));
        $audit->record('catalog.cid_imported', metadata: $stats + ['version' => $request->input('version')]);

        return back()->with('success', "CID importada: {$stats['created']} novos, {$stats['updated']} atualizados, {$stats['skipped']} linhas ignoradas.");
    }

    public function importMedications(Request $request, MedicationService $service, AuditLogger $audit): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);
        $stats = $service->import($request->file('file')->getRealPath(), $request->user()->id);
        $audit->record('catalog.medications_imported', metadata: $stats);

        return back()->with('success', "Medicamentos importados: {$stats['created']} novos, {$stats['skipped']} linhas ignoradas.");
    }
}
