<?php

namespace App\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Models\Company;
use App\Models\ReportPreference;
use App\Models\ReportRun;
use App\Models\WhatsappContact;
use App\Services\Reports\PeriodReport;
use App\Services\Reports\ReportDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Relatórios automáticos (escolhidos no cadastro pelo administrador e alteráveis aqui a qualquer momento). */
class ReportPreferenceController extends Controller
{
    public function show(Request $request, PeriodReport $reports, ReportDispatcher $dispatcher): View
    {
        $pref = ReportPreference::query()->firstOrNew([]);
        $companies = Company::query()->where('active', true)->orderBy('name')->get();
        $sample = null;
        if ($first = $companies->first()) {
            [$from, $to] = PeriodReport::lastPeriod('semanal');
            $sample = $reports->render($reports->facts($first, 'semanal', $from, $to));
        }

        return view('settings.reports', [
            'pref' => $pref, 'companies' => $companies, 'sample' => $sample,
            'contacts' => WhatsappContact::query()->where('active', true)->whereNotNull('verified_at')->orderBy('label')->get(),
            'defaultContact' => $dispatcher->contact($pref),
            'runs' => ReportRun::query()->latest()->limit(20)->get(),
            'templateOk' => (string) config('services.whatsapp.report_template') !== '',
            'plan' => $request->attributes->get('account')->plan(),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $d = $request->validate(['weekly' => ['boolean'], 'monthly' => ['boolean'], 'via_whatsapp' => ['boolean'], 'via_email' => ['boolean'],
            'all_companies' => ['boolean'], 'companies' => ['array'], 'companies.*' => ['string'], 'email' => ['nullable', 'email', 'max:190'], 'whatsapp_contact_id' => ['nullable', 'string']]);
        $ids = $request->boolean('all_companies', true) ? null : Company::query()->whereIn('id', (array) ($d['companies'] ?? []))->pluck('id')->all();
        $pref = ReportPreference::query()->firstOrNew([]);
        $pref->fill([
            'weekly' => $request->boolean('weekly'), 'monthly' => $request->boolean('monthly'),
            'via_whatsapp' => $request->boolean('via_whatsapp'), 'via_email' => $request->boolean('via_email'),
            'company_ids' => $ids ?: null, 'email' => ($d['email'] ?? null) ?: null,
            'whatsapp_contact_id' => ! empty($d['whatsapp_contact_id']) ? WhatsappContact::query()->whereKey($d['whatsapp_contact_id'])->value('id') : null,
        ])->save();
        $audit->log('relatorios.preferencias', $pref, $pref->only(['weekly', 'monthly', 'via_whatsapp', 'via_email', 'company_ids']));

        return back()->with('ok', $pref->enabled() ? 'Relatórios automáticos salvos.' : 'Relatórios automáticos desativados.');
    }
}
