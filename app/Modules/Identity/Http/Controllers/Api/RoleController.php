<?php

namespace App\Modules\Identity\Http\Controllers\Api;

use App\Core\Access\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Requests\RoleRequest;
use App\Modules\Identity\Http\Resources\RoleResource;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $service) {}

    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(Role::query()->with('permissions')->withCount('assignments')->orderBy('name')->get());
    }

    public function show(Role $role): RoleResource
    {
        return new RoleResource($role->load('permissions')->loadCount('assignments'));
    }

    public function store(RoleRequest $request): RoleResource
    {
        return new RoleResource($this->service->create($request->user(), $request->validated())->load('permissions'));
    }

    public function update(RoleRequest $request, Role $role): RoleResource
    {
        return new RoleResource($this->service->update($request->user(), $role, $request->validated())->load('permissions'));
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->service->delete($request->user(), $role);

        return response()->json(null, 204);
    }

    /** Catálogo de permissões de clínica, agrupado por módulo. */
    public function permissions(PermissionRegistry $registry): JsonResponse
    {
        return response()->json(['data' => collect($registry->modules())->map(fn ($m, $key) => [
            'module' => $key,
            'label' => $m['label'],
            'permissions' => collect($m['permissions'])->map(fn ($d, $k) => ['key' => $k, 'description' => $d])->values(),
        ])->values()]);
    }
}
