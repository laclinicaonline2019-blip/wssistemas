<?php

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Core\Access\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Requests\RoleRequest;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Services\RoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RoleWebController extends Controller
{
    public function __construct(
        private readonly RoleService $service,
        private readonly PermissionRegistry $registry,
    ) {}

    public function index(): View
    {
        return view('roles.index', ['roles' => Role::query()->withCount(['assignments', 'permissions'])->orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('roles.form', ['role' => new Role, 'selected' => [], 'modules' => $this->registry->modules()]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $role = $this->service->create($request->user(), $request->validated() + ['permissions' => []]);

        return redirect()->route('roles.edit', $role)->with('success', 'Perfil criado.');
    }

    public function edit(Role $role): View
    {
        return view('roles.form', ['role' => $role, 'selected' => $role->load('permissions')->permissionKeys(), 'modules' => $this->registry->modules()]);
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $this->service->update($request->user(), $role, $request->validated() + ['permissions' => []]);

        return back()->with('success', 'Perfil atualizado.');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->service->delete($request->user(), $role);

        return redirect()->route('roles.index')->with('success', 'Perfil excluído.');
    }
}
