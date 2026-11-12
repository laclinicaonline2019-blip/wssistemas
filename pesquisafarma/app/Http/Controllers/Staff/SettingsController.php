<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AiAssistant;
use App\Support\Activity;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public const BOOL = ['portal_enabled', 'loyalty_enabled', 'ai_enabled', 'auto_dispatch'];

    public function edit(AiAssistant $ai)
    {
        $values = [];
        foreach (array_merge(array_keys(Setting::DEFAULTS), ['auto_dispatch']) as $k) {
            $values[$k] = Setting::get($k, $k === 'auto_dispatch' ? '0' : null);
        }

        return view('staff.settings', ['s' => $values, 'aiConfigured' => filled(config('pesquisafarma.anthropic.api_key')), 'aiOn' => $ai->enabled(),
            'driver' => config('pesquisafarma.whatsapp_driver'), 'webhook' => url('/webhook/whatsapp/'.config('pesquisafarma.webhook_secret'))]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'company_name' => 'required|string|max:80', 'company_whatsapp' => 'nullable|string|max:20', 'radius_near_km' => 'required|numeric|min:0.5|max:50',
            'radius_far_km' => 'required|numeric|min:1|max:300', 'response_minutes' => 'required|integer|min:5|max:600', 'points_per_real' => 'required|numeric|min:0|max:100',
            'max_pharmacies' => 'required|integer|min:1|max:100', 'welcome_message' => 'required|string|max:1000',
        ]);
        foreach ($data as $k => $v) {
            Setting::put($k, (string) $v);
        }
        foreach (self::BOOL as $k) {
            Setting::put($k, $request->boolean($k) ? '1' : '0');
        }
        Activity::log('configuracoes', 'Configurações atualizadas', null, $data);

        return back()->with('ok', 'Configurações salvas.');
    }
}
