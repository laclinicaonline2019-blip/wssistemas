<?php

namespace App\Modules\Identity\Http\Controllers\Web;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Requests\SyncRolesRequest;
use App\Modules\Identity\Http\Requests\UserRequest;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AccessGuard;
use App\Modules\Identity\Services\UserService;
use App\Modules\Organization\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserWebController extends Controller
{
    public function __construct(
        private readonly UserService $service,
        private readonly AccessGuard $guard,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): View
    {
        $users = User::query()->manageableBy($this->context->allowedBranchIds())
            ->with(['roleAssignments.role', 'roleAssignments.branch'])
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->whereLike('name', "%{$s}%")->orWhereLike('email', "%{$s}%")))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('users.index', compact('users'));
    }

    public function create(): View
    {
        return view('users.form', ['user' => new User, ...$this->formData()]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['roles'] = $this->cleanRoles($data['roles'] ?? []);
        $user = $this->service->create($request->user(), $data);

        return redirect()->route('users.edit', $user)->with('success', 'Usuário criado. A senha deverá ser trocada no primeiro acesso.');
    }

    public function edit(Request $request, User $user): View
    {
        abort_unless($this->guard->canManageUser($request->user(), $user), 404);

        return view('users.form', ['user' => $user->load('roleAssignments'), ...$this->formData()]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->service->update($request->user(), $user, $request->validated());

        return back()->with('success', 'Usuário atualizado.');
    }

    public function syncRoles(SyncRolesRequest $request, User $user): RedirectResponse
    {
        $this->service->syncRoles($request->user(), $user, $this->cleanRoles($request->validated('roles')));

        return back()->with('success', 'Perfis de acesso atualizados.');
    }

    public function block(Request $request, User $user): RedirectResponse
    {
        $this->service->block($request->user(), $user);

        return back()->with('success', 'Usuário bloqueado e sessões encerradas.');
    }

    public function unblock(Request $request, User $user): RedirectResponse
    {
        $this->service->unblock($request->user(), $user);

        return back()->with('success', 'Usuário desbloqueado.');
    }

    private function formData(): array
    {
        return [
            'roles' => Role::query()->orderBy('name')->get(),
            'branches' => Branch::query()->active()->accessible($this->context->allowedBranchIds())->orderByDesc('is_headquarters')->orderBy('name')->get(),
            'companyWide' => $this->context->allowedBranchIds() === null,
        ];
    }

    /** Linhas vazias do formulário dinâmico são ignoradas; '' vira null (empresa toda). */
    private function cleanRoles(array $roles): array
    {
        return collect($roles)->filter(fn ($r) => ! empty($r['role_id']))
            ->map(fn ($r) => ['role_id' => $r['role_id'], 'branch_id' => ($r['branch_id'] ?? '') ?: null])
            ->values()->all();
    }
}
