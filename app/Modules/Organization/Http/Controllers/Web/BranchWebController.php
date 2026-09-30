<?php

namespace App\Modules\Organization\Http\Controllers\Web;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Organization\Http\Requests\BranchRequest;
use App\Modules\Organization\Models\Branch;
use App\Modules\Organization\Services\BranchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BranchWebController extends Controller
{
    public function __construct(
        private readonly BranchService $service,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): View
    {
        $branches = Branch::query()->accessible($this->context->allowedBranchIds())
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->whereLike('name', "%{$s}%")->orWhereLike('code', "%{$s}%")))
            ->orderByDesc('is_headquarters')->orderBy('name')->paginate(25)->withQueryString();

        return view('branches.index', compact('branches'));
    }

    public function create(): View
    {
        return view('branches.form', ['branch' => new Branch]);
    }

    public function store(BranchRequest $request): RedirectResponse
    {
        $branch = $this->service->create($request->user(), $request->validated());

        return redirect()->route('branches.index')->with('success', "Filial {$branch->name} criada.");
    }

    public function edit(Branch $branch): View
    {
        $this->ensureAccessible($branch);

        return view('branches.form', compact('branch'));
    }

    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        $this->ensureAccessible($branch);
        $this->service->update($request->user(), $branch, $request->validated());

        return redirect()->route('branches.index')->with('success', 'Filial atualizada.');
    }

    public function status(Request $request, Branch $branch): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        $this->service->setStatus($request->user(), $branch, $data['status']);

        return back()->with('success', $data['status'] === 'active' ? 'Filial reativada.' : 'Filial desativada.');
    }

    private function ensureAccessible(Branch $branch): void
    {
        $allowed = $this->context->allowedBranchIds();
        abort_if($allowed !== null && ! in_array($branch->id, $allowed, true), 404);
    }
}
