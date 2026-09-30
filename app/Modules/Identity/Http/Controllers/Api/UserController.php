<?php

namespace App\Modules\Identity\Http\Controllers\Api;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Requests\SyncRolesRequest;
use App\Modules\Identity\Http\Requests\UserRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\AccessGuard;
use App\Modules\Identity\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $service,
        private readonly AccessGuard $guard,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->manageableBy($this->context->allowedBranchIds())
            ->with(['roleAssignments.role', 'roleAssignments.branch'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->whereLike('name', "%{$s}%")->orWhereLike('email', "%{$s}%")))
            ->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return UserResource::collection($users);
    }

    public function show(Request $request, User $user): UserResource
    {
        abort_unless($this->guard->canManageUser($request->user(), $user) || $request->user()->is($user), 404);

        return new UserResource($user->load(['roleAssignments.role', 'roleAssignments.branch']));
    }

    public function store(UserRequest $request): UserResource
    {
        $user = $this->service->create($request->user(), $request->validated());

        return new UserResource($user->load(['roleAssignments.role', 'roleAssignments.branch']));
    }

    public function update(UserRequest $request, User $user): UserResource
    {
        return new UserResource($this->service->update($request->user(), $user, $request->validated()));
    }

    public function block(Request $request, User $user): UserResource
    {
        return new UserResource($this->service->block($request->user(), $user));
    }

    public function unblock(Request $request, User $user): UserResource
    {
        return new UserResource($this->service->unblock($request->user(), $user));
    }

    public function syncRoles(SyncRolesRequest $request, User $user): UserResource
    {
        $this->service->syncRoles($request->user(), $user, $request->validated('roles'));

        return new UserResource($user->load(['roleAssignments.role', 'roleAssignments.branch']));
    }
}
