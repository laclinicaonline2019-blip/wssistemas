<?php

namespace App\Modules\Organization\Http\Controllers\Api;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Organization\Http\Requests\BranchRequest;
use App\Modules\Organization\Http\Resources\BranchResource;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Services\BranchService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class BranchController extends Controller
{
    public function __construct(
        private readonly BranchService $service,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $branches = Branch::query()
            ->accessible($this->context->allowedBranchIds())
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->whereLike('name', "%{$s}%")->orWhereLike('code', "%{$s}%")))
            ->orderByDesc('is_headquarters')->orderBy('name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return BranchResource::collection($branches);
    }

    public function show(Branch $branch): BranchResource
    {
        $this->ensureAccessible($branch);

        return new BranchResource($branch);
    }

    public function store(BranchRequest $request): BranchResource
    {
        return new BranchResource($this->service->create($request->user(), $request->validated()));
    }

    public function update(BranchRequest $request, Branch $branch): BranchResource
    {
        $this->ensureAccessible($branch);

        return new BranchResource($this->service->update($request->user(), $branch, $request->validated()));
    }

    public function status(Request $request, Branch $branch): BranchResource
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);

        return new BranchResource($this->service->setStatus($request->user(), $branch, $data['status']));
    }

    private function ensureAccessible(Branch $branch): void
    {
        $allowed = $this->context->allowedBranchIds();
        abort_if($allowed !== null && ! in_array($branch->id, $allowed, true), 404);
    }
}
