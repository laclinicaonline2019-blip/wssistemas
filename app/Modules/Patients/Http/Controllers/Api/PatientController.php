<?php

namespace App\Modules\Patients\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Patients\Http\Requests\PatientRequest;
use App\Modules\Patients\Http\Resources\PatientResource;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\PatientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PatientController extends Controller
{
    public function __construct(private readonly PatientService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'inactive'])]]);

        $patients = Patient::query()->search($request->query('search'))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderBy('search_name')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return PatientResource::summaries($patients);
    }

    public function show(Patient $patient): PatientResource
    {
        $this->service->recordView($patient, 'api');

        return new PatientResource($patient->load(['contacts', 'insurances', 'consents']));
    }

    public function store(PatientRequest $request): PatientResource
    {
        $patient = $this->service->create($request->user(), $request->validated(), $request->boolean('confirm_duplicate'));

        return new PatientResource($patient->load(['contacts', 'insurances', 'consents']));
    }

    public function update(PatientRequest $request, Patient $patient): PatientResource
    {
        return new PatientResource($this->service->update($request->user(), $patient, $request->validated())->load(['contacts', 'insurances', 'consents']));
    }

    public function status(Request $request, Patient $patient): PatientResource
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);

        return new PatientResource($this->service->setStatus($patient, $data['status']));
    }

    public function consent(Request $request, Patient $patient): JsonResponse
    {
        $data = self::validateConsent($request);
        $consent = $this->service->recordConsent($request->user(), $patient, $data['purpose'], (bool) $data['granted'], $data['channel'], $data['notes'] ?? null);

        return response()->json(['data' => $consent->only(['id', 'purpose', 'term_version', 'granted', 'channel'])], 201);
    }

    public function export(Request $request, Patient $patient): JsonResponse
    {
        return response()->json(['data' => $this->service->export($request->user(), $patient)]);
    }

    public function anonymize(Request $request, Patient $patient): PatientResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:255'], 'confirm' => ['accepted']]);

        return new PatientResource($this->service->anonymize($request->user(), $patient, $data['reason']));
    }

    public static function validateConsent(Request $request): array
    {
        return $request->validate([
            'purpose' => ['required', Rule::in(array_keys(config('consents.purposes')))],
            'granted' => ['required', 'boolean'],
            'channel' => ['required', Rule::in(array_keys(config('consents.channels')))],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
