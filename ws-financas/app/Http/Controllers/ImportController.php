<?php

namespace App\Http\Controllers;

use App\Core\Access\ApprovalPolicy;
use App\Models\ImportBatch;
use App\Models\LocalImport;
use App\Services\Import\StatementImport;
use App\Services\FinanceService;
use App\Services\ImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Migração do armazenamento local do navegador (sistema HTML original) para o banco. */
class ImportController extends Controller
{
    public function show(): View
    {
        return view('settings.import', ['history' => LocalImport::query()->latest()->limit(20)->get(),
            'batches' => ImportBatch::query()->latest()->limit(10)->get(['id', 'filename', 'format', 'status', 'imported', 'duplicates', 'created_at'])]);
    }

    // ------------------------------------------------------------ 1.1.7: extrato bancário (OFX, CSV, Excel, PDF) com prévia

    public function uploadStatement(Request $request, StatementImport $import, FinanceService $finance): RedirectResponse
    {
        $request->validate(['empresa' => ['required', 'string'], 'extrato' => ['required', 'file', 'max:5120', 'extensions:ofx,qfx,csv,txt,xlsx,pdf']],
            ['extrato.extensions' => 'Envie o extrato em OFX, CSV, Excel (.xlsx) ou PDF.', 'extrato.max' => 'Arquivo muito grande (máximo 5 MB).']);
        $batch = $import->preview($finance->company($request->input('empresa')), $request->file('extrato'), $request->user()->id);

        return redirect()->route('import.statement.show', $batch->id);
    }

    public function showStatement(string $batch, FinanceService $finance): View
    {
        $b = ImportBatch::query()->findOrFail($batch);
        $company = $finance->company($b->company_id);

        return view('settings.import-preview', ['batch' => $b, 'company' => $company,
            'catsDesp' => $company->categories('despesa'), 'catsRec' => $company->categories('receita'),
            'centers' => ApprovalPolicy::allowedCostCenters($company->id, ApprovalPolicy::forUser(auth()->id()))]);
    }

    public function confirmStatement(Request $request, string $batch, StatementImport $import): RedirectResponse
    {
        $request->validate(['linhas' => ['array'], 'linhas.*' => ['integer'], 'cat' => ['array'], 'cat.*' => ['nullable', 'string', 'max:60'], 'centro' => ['nullable', 'string']]);
        $b = ImportBatch::query()->findOrFail($batch);
        $r = $import->confirm($b, (array) $request->input('linhas', []), (array) $request->input('cat', []), $request->input('centro'));

        return redirect()->route('import.show')->with('ok', sprintf('Extrato importado: %d lançamento(s) criado(s), %d já existiam (não duplicados), %d não selecionado(s).', $r['importadas'], $r['duplicadas'], $r['ignoradas']));
    }

    public function cancelStatement(string $batch): RedirectResponse
    {
        $b = ImportBatch::query()->where('status', 'previa')->findOrFail($batch);
        $b->update(['status' => 'cancelado', 'rows' => []]);

        return redirect()->route('import.show')->with('ok', 'Importação cancelada. Nada foi lançado.');
    }

    public function store(Request $request, ImportService $import, FinanceService $finance): RedirectResponse
    {
        $request->validate(['empresa' => ['required', 'string'], 'arquivo' => ['nullable', 'file', 'max:20480', 'mimetypes:application/json,text/plain'], 'conteudo' => ['nullable', 'string', 'max:20000000']]);
        $json = $request->file('arquivo') ? (string) file_get_contents($request->file('arquivo')->getRealPath()) : (string) $request->input('conteudo');
        if (trim($json) === '') {
            return back()->with('error', 'Nenhum dado encontrado. Envie o arquivo exportado ou use a leitura automática.');
        }
        $sum = $import->import($finance->company($request->input('empresa')), $json, $request->user()->id);

        return back()->with('ok', sprintf('Importação concluída: %d despesas, %d entradas, %d dívidas. %d já existiam (não duplicadas), %d exemplos ignorados, %d inválidos. Nenhum dado foi apagado.',
            $sum['despesas'], $sum['receitas'], $sum['dividas'], $sum['ja_existentes'], $sum['exemplos_ignorados'], $sum['invalidos']));
    }
}
