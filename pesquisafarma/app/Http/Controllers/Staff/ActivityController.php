<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

/** Tudo que cada televendas faz fica registrado aqui. */
class ActivityController extends Controller
{
    public function index(Request $request)
    {
        $logs = ActivityLog::query()->with('user')
            ->when(! $request->user()->isAdmin(), fn ($q) => $q->where('user_id', $request->user()->id))
            ->when($request->usuario, fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->acao, fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($request->de, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->ate, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest('id')->paginate(50)->withQueryString();

        return view('staff.activity', ['logs' => $logs, 'users' => User::orderBy('name')->get()]);
    }
}
