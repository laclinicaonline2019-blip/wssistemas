<?php

namespace App\Modules\Platform\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Http\Requests\PlanRequest;
use App\Modules\Platform\Http\Resources\PlanResource;
use App\Modules\Platform\Models\SaasPlan;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlatformPlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection(SaasPlan::query()->orderBy('price_monthly_cents')->get());
    }

    public function store(PlanRequest $request): PlanResource
    {
        return new PlanResource(SaasPlan::create($request->validated()));
    }

    public function update(PlanRequest $request, SaasPlan $plan): PlanResource
    {
        $data = $request->validated();

        if (isset($data['limits'])) {
            $data['limits'] = array_replace($plan->limits ?? [], $data['limits']);
        }

        $plan->update($data);

        return new PlanResource($plan);
    }
}
