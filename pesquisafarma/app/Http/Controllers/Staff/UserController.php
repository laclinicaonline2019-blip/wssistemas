<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Pharmacy;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index()
    {
        return view('staff.users', ['users' => User::query()->with('pharmacy')->orderBy('role')->orderBy('name')->get(), 'pharmacies' => Pharmacy::orderBy('trade_name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|unique:users,email', 'role' => 'required|in:admin,televendas,farmacia',
            'pharmacy_id' => 'nullable|required_if:role,farmacia|exists:pharmacies,id', 'phone' => 'nullable|string|max:20', 'password' => ['required', Password::min(8)]]);
        $u = User::create($data);
        Activity::log('usuario.criado', "Usuário {$u->email} ({$u->role}) criado", $u);

        return back()->with('ok', 'Usuário criado.');
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'role' => 'required|in:admin,televendas,farmacia', 'pharmacy_id' => 'nullable|exists:pharmacies,id', 'password' => ['nullable', Password::min(8)]]);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $data['active'] = $request->boolean('active');
        abort_if($user->id === auth()->id() && (! $data['active'] || $data['role'] !== 'admin'), 422, 'Você não pode remover seu próprio acesso de administrador.');
        $user->update($data);
        Activity::log('usuario.editado', "Usuário {$user->email} atualizado", $user);

        return back()->with('ok', 'Usuário atualizado.');
    }
}
