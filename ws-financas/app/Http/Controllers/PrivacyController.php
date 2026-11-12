<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\PrivacyService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PrivacyController extends Controller
{
    public function show(Request $request): View
    {
        return view('settings.privacy', ['account' => $request->attributes->get('account')]);
    }

    /** Portabilidade (LGPD art. 18, V): todos os dados do cliente em JSON. */
    public function export(Request $request, PrivacyService $privacy): Response
    {
        $data = $privacy->export($request->attributes->get('account'));

        return response()->streamDownload(fn () => print (json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            'ws-financas-dados-'.now()->format('Y-m-d').'.json', ['Content-Type' => 'application/json']);
    }

    public function audit(Request $request): View
    {
        $logs = AuditLog::query()->where('account_id', $request->attributes->get('account')->id)->latest('id')->paginate(50);
        $users = User::query()->whereIn('id', $logs->pluck('user_id')->filter())->pluck('name', 'id');

        return view('settings.audit', ['logs' => $logs, 'users' => $users]);
    }
}
