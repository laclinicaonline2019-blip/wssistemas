<?php

namespace App\Http\Controllers;

use App\Models\LocalImport;
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
        return view('settings.import', ['history' => LocalImport::query()->latest()->limit(20)->get()]);
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
