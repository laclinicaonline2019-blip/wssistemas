<?php

namespace App\Modules\Documents\Http;

use App\Modules\Clinical\Models\Encounter;
use App\Modules\Clinical\Models\Medication;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** Validação e emissão compartilhadas entre web e API. */
final class DocumentRequests
{
    public const ATTRIBUTES = [
        'items' => 'medicamentos', 'items.*.posology' => 'posologia', 'items.*.quantity' => 'quantidade',
        'days' => 'dias', 'start_date' => 'início', 'start_time' => 'chegada', 'end_time' => 'saída',
        'exams' => 'exames', 'body' => 'texto', 'reason' => 'motivo',
    ];

    public static function rules(string $type): array
    {
        $common = [
            'patient_id' => ['required', 'string', 'size:26'],
            'encounter_id' => ['nullable', 'string', 'size:26'],
            'branch_id' => ['nullable', 'string', 'size:26'],
        ];

        return $common + match ($type) {
            'prescription' => [
                'items' => ['required', 'array', 'min:1', 'max:20'],
                'items.*.medication_id' => ['nullable', 'string', 'size:26'],
                'items.*.name' => ['nullable', 'string', 'max:250'],
                'items.*.quantity' => ['nullable', 'string', 'max:60'],
                'items.*.posology' => ['nullable', 'string', 'max:500'],
                'items.*.route' => ['nullable', 'string', 'max:40'],
                'items.*.control_type' => ['nullable', Rule::in(array_keys(Medication::CONTROL_TYPES))],
                'items.*.notification_number' => ['nullable', 'string', 'max:20'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            'certificate' => [
                'subtype' => ['required', Rule::in(array_keys(MedicalDocument::CERTIFICATE_SUBTYPES))],
                'days' => ['nullable', 'required_if:subtype,leave', 'integer', 'between:1,365'],
                'start_date' => ['nullable', 'date'],
                'start_time' => ['nullable', 'required_if:subtype,attendance', 'date_format:H:i'],
                'end_time' => ['nullable', 'required_if:subtype,attendance', 'date_format:H:i'],
                'cid_code_id' => ['nullable', 'string', 'size:26'],
                'cid_authorized' => ['nullable', 'boolean'],
                'purpose' => ['nullable', 'string', 'max:200'],
                'notes' => ['nullable', 'string', 'max:500'],
            ],
            'exam_request' => [
                'exams' => ['required', 'array', 'min:1', 'max:40'],
                'exams.*' => ['nullable', 'string', 'max:200'],
                'indication' => ['nullable', 'string', 'max:500'],
                'cid_code_id' => ['nullable', 'string', 'size:26'],
                'urgent' => ['nullable', 'boolean'],
            ],
            'report' => [
                'subtype' => ['required', Rule::in(array_keys(MedicalDocument::REPORT_SUBTYPES))],
                'title' => ['nullable', 'string', 'max:150'],
                'recipient' => ['nullable', 'string', 'max:150'],
                'body' => ['required', 'string', 'max:10000'],
            ],
        };
    }

    /** @return Collection<int, MedicalDocument> */
    public static function issue(DocumentService $service, User $user, string $type, array $data): Collection
    {
        $patient = Patient::query()->findOrFail($data['patient_id']);
        $encounter = isset($data['encounter_id']) ? Encounter::query()->where('patient_id', $patient->id)->findOrFail($data['encounter_id']) : null;
        $branch = $data['branch_id'] ?? null;

        return match ($type) {
            'prescription' => $service->issuePrescription($user, $patient, array_values(array_filter($data['items'], fn ($i) => ! empty($i['medication_id']) || ! empty($i['name']))), $encounter, $data['notes'] ?? null, $branch),
            'certificate' => collect([$service->issueCertificate($user, $patient, $data, $encounter, $branch)]),
            'exam_request' => collect([$service->issueExamRequest($user, $patient, $data, $encounter, $branch)]),
            'report' => collect([$service->issueReport($user, $patient, $data, $encounter, $branch)]),
        };
    }
}
