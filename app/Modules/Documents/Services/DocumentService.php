<?php

namespace App\Modules\Documents\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Support\Format;
use App\Core\Support\SequenceGenerator;
use App\Core\Tenancy\TenantContext;
use App\Modules\Clinical\Models\CidCode;
use App\Modules\Clinical\Models\Encounter;
use App\Modules\Clinical\Models\Medication;
use App\Modules\Clinical\Services\EncounterService;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Documents\Models\MedicalDocument;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Company;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Emissão de documentos médicos.
 *
 * Receitas são separadas automaticamente conforme a Portaria SVS/MS 344/98:
 * - venda livre → receita simples;
 * - antimicrobianos (RDC 471/2021) e listas C1, C4, C5 → receita de controle especial em 2 vias;
 * - listas A1–A3, B1, B2, C2, C3 → exigem a Notificação de Receita (talão oficial da
 *   Vigilância Sanitária): o sistema NÃO imprime receita para elas, apenas registra o
 *   número da notificação preenchida à mão.
 *
 * Todo documento recebe um cabeçalho "congelado" (clínica, médico e paciente no
 * momento da emissão), número sequencial, selo HMAC do conteúdo e código público
 * de verificação (QR Code).
 */
class DocumentService
{
    public const SPECIAL_CONTROL = ['antimicrobial', 'C1', 'C4', 'C5'];

    public const NOTIFICATION_REQUIRED = ['A1', 'A2', 'A3', 'B1', 'B2', 'C2', 'C3'];

    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(
        private readonly TenantContext $context,
        private readonly EncounterService $encounters,
        private readonly SequenceGenerator $sequences,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<int, array{medication_id?: ?string, name?: ?string, quantity?: ?string, posology: string, route?: ?string, control_type?: ?string, notification_number?: ?string}>  $items
     * @return Collection<int, MedicalDocument>
     */
    public function issuePrescription(User $actor, Patient $patient, array $items, ?Encounter $encounter = null, ?string $notes = null, ?string $branchId = null): Collection
    {
        $doctor = $this->requireDoctor($actor, $patient, $encounter);

        if ($items === []) {
            throw new BusinessRuleViolation('Inclua ao menos um medicamento.', 'empty_prescription');
        }

        $medications = Medication::query()->whereIn('id', collect($items)->pluck('medication_id')->filter())->get()->keyBy('id');
        $groups = ['prescription' => [], 'special_prescription' => [], 'notification_record' => []];

        foreach (array_values($items) as $i => $item) {
            $n = $i + 1;
            $med = isset($item['medication_id']) && $item['medication_id'] ? ($medications[$item['medication_id']] ?? null) : null;

            if (! empty($item['medication_id']) && ! $med) {
                throw new BusinessRuleViolation("Item {$n}: medicamento não encontrado.", 'invalid_medication');
            }

            $name = $med?->label() ?? trim((string) ($item['name'] ?? ''));
            $control = $med?->control_type ?? ($item['control_type'] ?? 'none');

            if ($name === '' || trim((string) ($item['posology'] ?? '')) === '') {
                throw new BusinessRuleViolation("Item {$n}: informe o medicamento e a posologia.", 'incomplete_item');
            }
            if (! array_key_exists($control, Medication::CONTROL_TYPES)) {
                throw new BusinessRuleViolation("Item {$n}: tipo de controle inválido.", 'invalid_control');
            }

            $entry = [
                'name' => mb_substr($name, 0, 250),
                'quantity' => $this->clean($item['quantity'] ?? null, 60),
                'posology' => $this->clean($item['posology'], 500),
                'route' => $this->clean($item['route'] ?? null, 40) ?? $med?->route,
                'control_type' => $control,
                'medication_id' => $med?->id,
            ];

            if (in_array($control, self::NOTIFICATION_REQUIRED, true)) {
                $number = $this->clean($item['notification_number'] ?? null, 20);
                if (! $number || ! $entry['quantity']) {
                    throw new BusinessRuleViolation("Item {$n} ({$name}) é da lista {$control}: exige a Notificação de Receita oficial (talão da Vigilância Sanitária). Preencha o talão e informe o número da notificação e a quantidade.", 'notification_required');
                }
                $entry['notification_number'] = $number;
                $groups['notification_record'][] = $entry;
            } elseif (in_array($control, self::SPECIAL_CONTROL, true)) {
                if (! $entry['quantity']) {
                    throw new BusinessRuleViolation("Item {$n} ({$name}) é de controle especial: informe a quantidade (preferencialmente também por extenso).", 'quantity_required');
                }
                $groups['special_prescription'][] = $entry;
            } else {
                $groups['prescription'][] = $entry;
            }
        }

        $notes = $this->clean($notes, 2000);

        return DB::transaction(function () use ($actor, $doctor, $patient, $encounter, $branchId, $groups, $notes) {
            $ctx = $this->issueContext($actor, $doctor, $patient, $encounter, $branchId);
            $docs = collect();

            foreach ($groups as $type => $entries) {
                if ($entries === []) {
                    continue;
                }

                $validUntil = match ($type) {
                    // Antimicrobianos: 10 dias (RDC 471/2021); controle especial: 30 dias (Portaria 344/98).
                    'special_prescription' => now($ctx['tz'])->addDays(collect($entries)->contains('control_type', 'antimicrobial') ? 10 : 30)->toDateString(),
                    default => null,
                };

                $docs->push($this->create($ctx, $type, null, ['items' => $entries, 'notes' => $type === 'notification_record' ? null : $notes], $validUntil));
            }

            return $docs;
        });
    }

    /** Atestado de afastamento ou de comparecimento. O CID só é incluído com autorização do paciente (CFM). */
    public function issueCertificate(User $actor, Patient $patient, array $data, ?Encounter $encounter = null, ?string $branchId = null): MedicalDocument
    {
        $doctor = $this->requireDoctor($actor, $patient, $encounter);
        $subtype = $data['subtype'] ?? 'leave';

        if (! array_key_exists($subtype, MedicalDocument::CERTIFICATE_SUBTYPES)) {
            throw new BusinessRuleViolation('Tipo de atestado inválido.', 'invalid_subtype');
        }

        $cid = null;
        if (! empty($data['cid_code_id'])) {
            if (! filter_var($data['cid_authorized'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                throw new BusinessRuleViolation('O CID só pode constar no atestado com autorização expressa do paciente (Resolução CFM 1.658/2002). Marque a autorização ou remova o CID.', 'cid_not_authorized');
            }
            $c = CidCode::query()->findOrFail($data['cid_code_id']);
            $cid = ['code' => $c->code, 'description' => $c->description];
        }

        return DB::transaction(function () use ($actor, $doctor, $patient, $encounter, $branchId, $data, $subtype, $cid) {
            $ctx = $this->issueContext($actor, $doctor, $patient, $encounter, $branchId);
            $today = CarbonImmutable::now($ctx['tz'])->startOfDay();
            $who = $ctx['header']['patient']['name'].($ctx['header']['patient']['cpf'] ? ', CPF '.$ctx['header']['patient']['cpf'] : '');
            $attended = ['F' => 'atendida', 'M' => 'atendido'][$patient->sex] ?? 'atendido(a)';
            $purpose = $this->clean($data['purpose'] ?? null, 200);

            if ($subtype === 'leave') {
                $days = (int) ($data['days'] ?? 0);
                $start = CarbonImmutable::parse($data['start_date'] ?? $today->toDateString(), $ctx['tz'])->startOfDay();

                if ($days < 1 || $days > 365) {
                    throw new BusinessRuleViolation('Informe de 1 a 365 dias de afastamento.', 'invalid_days');
                }
                // Atestado corresponde ao atendimento: início no máximo 3 dias antes e até 30 dias depois.
                if ($start->lt($today->subDays(3)) || $start->gt($today->addDays(30))) {
                    throw new BusinessRuleViolation('Data de início do afastamento fora do permitido (até 3 dias antes ou 30 dias depois da emissão).', 'invalid_start_date');
                }

                $end = $start->addDays($days - 1);
                $text = "Atesto, para os devidos fins, que {$who}, foi {$attended} por mim nesta data e necessita de {$days} ("
                    .Format::numberInWords($days).') dia'.($days > 1 ? 's' : '').' de afastamento de suas atividades'
                    .($days > 1 ? ", no período de {$start->format('d/m/Y')} a {$end->format('d/m/Y')}" : ", em {$start->format('d/m/Y')}")
                    .($purpose ? ", para fins de {$purpose}" : '').'.';
                $content = ['days' => $days, 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()];
            } else {
                $from = $data['start_time'] ?? null;
                $to = $data['end_time'] ?? null;

                if (! preg_match('/^\d{2}:\d{2}$/', (string) $from) || ! preg_match('/^\d{2}:\d{2}$/', (string) $to) || $to <= $from) {
                    throw new BusinessRuleViolation('Informe os horários de chegada e saída (saída depois da chegada).', 'invalid_time');
                }

                $text = "Declaro, para os devidos fins, que {$who}, esteve em atendimento médico nesta unidade no dia {$today->format('d/m/Y')}, "
                    ."das {$from} às {$to}".($purpose ? ", para fins de {$purpose}" : '').'.';
                $content = ['date' => $today->toDateString(), 'start_time' => $from, 'end_time' => $to];
            }

            if ($cid) {
                $text .= " CID-10: {$cid['code']} (incluído com autorização do paciente).";
            }

            return $this->create($ctx, 'certificate', $subtype, $content + [
                'text' => $text, 'cid' => $cid, 'purpose' => $purpose, 'notes' => $this->clean($data['notes'] ?? null, 500),
            ], null);
        });
    }

    public function issueExamRequest(User $actor, Patient $patient, array $data, ?Encounter $encounter = null, ?string $branchId = null): MedicalDocument
    {
        $doctor = $this->requireDoctor($actor, $patient, $encounter);
        $exams = collect($data['exams'] ?? [])->map(fn ($e) => $this->clean($e, 200))->filter()->unique()->values()->all();

        if ($exams === [] || count($exams) > 40) {
            throw new BusinessRuleViolation('Informe de 1 a 40 exames.', 'invalid_exams');
        }

        $cid = ! empty($data['cid_code_id']) ? CidCode::query()->findOrFail($data['cid_code_id']) : null;

        return DB::transaction(fn () => $this->create($this->issueContext($actor, $doctor, $patient, $encounter, $branchId), 'exam_request', null, [
            'exams' => $exams,
            'indication' => $this->clean($data['indication'] ?? null, 500),
            'cid' => $cid ? ['code' => $cid->code, 'description' => $cid->description] : null,
            'urgent' => filter_var($data['urgent'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ], null));
    }

    /** Relatório, declaração ou encaminhamento (texto livre do médico). */
    public function issueReport(User $actor, Patient $patient, array $data, ?Encounter $encounter = null, ?string $branchId = null): MedicalDocument
    {
        $doctor = $this->requireDoctor($actor, $patient, $encounter);
        $subtype = $data['subtype'] ?? 'report';
        $body = $this->clean($data['body'] ?? null, 10000);

        if (! array_key_exists($subtype, MedicalDocument::REPORT_SUBTYPES) || ! $body) {
            throw new BusinessRuleViolation('Informe o tipo e o texto do documento.', 'invalid_report');
        }

        return DB::transaction(fn () => $this->create($this->issueContext($actor, $doctor, $patient, $encounter, $branchId), 'report', $subtype, [
            'title' => $this->clean($data['title'] ?? null, 150) ?? MedicalDocument::REPORT_SUBTYPES[$subtype],
            'recipient' => $this->clean($data['recipient'] ?? null, 150),
            'body' => $body,
        ], null));
    }

    public function cancel(User $actor, MedicalDocument $doc, string $reason): MedicalDocument
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw new BusinessRuleViolation('Informe o motivo do cancelamento (mínimo 10 caracteres).', 'reason_required');
        }

        // Médico só cancela os próprios documentos; administradores (não médicos) com a permissão podem cancelar.
        $doctor = $this->encounters->doctorFor($actor);
        if ($doctor && $doctor->id !== $doc->doctor_id) {
            throw new BusinessRuleViolation('Somente o médico emitente pode cancelar este documento.', 'not_author', 403);
        }

        return DB::transaction(function () use ($actor, $doc, $reason) {
            $locked = MedicalDocument::query()->whereKey($doc->id)->lockForUpdate()->firstOrFail();

            if ($locked->isCancelled()) {
                throw new BusinessRuleViolation('Documento já cancelado.', 'already_cancelled', 409);
            }

            $locked->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => mb_substr($reason, 0, 500)])->save();
            $this->audit->record('document.cancelled', $locked, metadata: ['type' => $locked->type, 'number' => $locked->number, 'reason' => $locked->cancel_reason, 'patient_id' => $locked->patient_id]);

            return $locked;
        });
    }

    /** Registra uma impressão (1ª via ou reimpressão). Documento cancelado não é impresso. */
    public function registerPrint(MedicalDocument $doc, string $format): void
    {
        if ($doc->isCancelled()) {
            throw new BusinessRuleViolation('Documento cancelado não pode ser impresso.', 'document_cancelled', 409);
        }

        MedicalDocument::query()->whereKey($doc->id)->update(['print_count' => DB::raw('print_count + 1'), 'last_printed_at' => now(), 'updated_at' => now()]);
        $doc->refresh();
        $this->audit->record('document.printed', $doc, metadata: ['type' => $doc->type, 'number' => $doc->number, 'format' => $format, 'copy' => $doc->print_count, 'patient_id' => $doc->patient_id]);
    }

    public function verify(MedicalDocument $doc): bool
    {
        return hash_equals($this->hash($doc->company_id, $doc->getAttributes()), $doc->content_hash);
    }

    /** Busca pública pelo código de verificação (sem contexto de empresa). */
    public function findByCode(string $code): ?MedicalDocument
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));

        if (strlen($code) !== 12) {
            return null;
        }

        return $this->context->runAsSystem(fn () => MedicalDocument::query()->withoutGlobalScopes()->where('verification_code', $code)->first());
    }

    public function validationUrl(MedicalDocument $doc): string
    {
        return route('documents.validate', $doc->formattedCode());
    }

    public function qrDataUri(string $text): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(140, 1), new SvgImageBackEnd)))->writeString($text);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    // ------------------------------------------------------------------ internos

    private function requireDoctor(User $actor, Patient $patient, ?Encounter $encounter): Doctor
    {
        $doctor = $this->encounters->doctorFor($actor)
            ?? throw new BusinessRuleViolation('Somente médicos com cadastro ativo emitem documentos médicos.', 'not_a_doctor', 403);

        if ($encounter && ($encounter->doctor_id !== $doctor->id || $encounter->patient_id !== $patient->id)) {
            throw new BusinessRuleViolation('Atendimento de outro médico ou de outro paciente.', 'invalid_encounter', 403);
        }

        if ($patient->isAnonymized() || $patient->status !== 'active') {
            throw new BusinessRuleViolation('Paciente inativo ou anonimizado.', 'invalid_patient');
        }

        return $doctor;
    }

    /** Unidade e cabeçalho "congelado" do documento. */
    private function issueContext(User $actor, Doctor $doctor, Patient $patient, ?Encounter $encounter, ?string $branchId): array
    {
        $branchId = $encounter?->branch_id ?? $branchId ?? $this->context->branchId() ?? $patient->home_branch_id
            ?? $doctor->branches()->value('branches.id');
        $branch = Branch::query()->accessible($this->context->allowedBranchIds())->find($branchId)
            ?? throw new BusinessRuleViolation('Selecione a unidade de atendimento.', 'branch_required');
        $company = Company::query()->findOrFail($this->context->companyId());
        $specialty = $doctor->specialties()->orderByRaw('CASE WHEN doctor_specialty.rqe IS NULL THEN 1 ELSE 0 END')->first();

        return [
            'actor' => $actor, 'doctor' => $doctor, 'patient' => $patient, 'encounter' => $encounter, 'branch' => $branch,
            'tz' => $branch->timezone ?: 'America/Sao_Paulo', 'group' => (string) Str::ulid(),
            'header' => [
                'clinic' => [
                    'name' => $company->trade_name, 'legal_name' => $company->legal_name, 'document' => Format::cnpj($company->document),
                    'branch' => $branch->name, 'address' => $branch->fullAddress(), 'zip_code' => Format::cep($branch->zip_code),
                    'city' => $branch->city, 'state' => $branch->state, 'phone' => Format::phone($branch->phone ?? $company->phone),
                ],
                'doctor' => [
                    'name' => $doctor->displayName(), 'registration' => $doctor->registration(),
                    'specialty' => $specialty?->name, 'rqe' => $specialty?->pivot?->rqe,
                ],
                'patient' => [
                    'name' => $patient->displayName(), 'civil_name' => $patient->social_name ? $patient->name : null,
                    'cpf' => Format::cpf($patient->cpf), 'birth_date' => $patient->birth_date?->format('d/m/Y'), 'age' => $patient->age(),
                    'sex' => $patient->sex, 'record_number' => $patient->record_number,
                    'address' => collect([trim(($patient->street ?? '').', '.($patient->number ?? ''), ', '), $patient->complement, $patient->district,
                        trim(($patient->city ?? '').' - '.($patient->state ?? ''), ' -'), Format::cep($patient->zip_code)])->filter()->implode(' · ') ?: null,
                    'phone' => Format::phone($patient->whatsapp ?? $patient->phone),
                ],
            ],
        ];
    }

    private function create(array $ctx, string $type, ?string $subtype, array $body, ?string $validUntil): MedicalDocument
    {
        $companyId = $this->context->companyId();
        $attributes = [
            'branch_id' => $ctx['branch']->id, 'patient_id' => $ctx['patient']->id, 'doctor_id' => $ctx['doctor']->id,
            'encounter_id' => $ctx['encounter']?->id, 'group_id' => $ctx['group'],
            'number' => $this->sequences->next($companyId, 'medical_document'),
            'type' => $type, 'subtype' => $subtype,
            'content' => $ctx['header'] + $body,
            'verification_code' => $this->newCode(),
            'issued_at' => now()->format('Y-m-d H:i:s'),
            'valid_until' => $validUntil, 'issued_by' => $ctx['actor']->id,
        ];
        $attributes['content_hash'] = $this->hash($companyId, $attributes);

        $doc = MedicalDocument::create($attributes);

        // Sem conteúdo clínico na trilha: apenas tipo, número e quantidade de itens.
        $this->audit->record('document.issued', $doc, metadata: [
            'type' => $type, 'subtype' => $subtype, 'number' => $doc->number, 'patient_id' => $doc->patient_id,
            'items' => count($body['items'] ?? $body['exams'] ?? []), 'encounter_id' => $doc->encounter_id,
        ]);

        return $doc;
    }

    private function hash(string $companyId, array $a): string
    {
        $canonical = function ($value) use (&$canonical) {
            if (is_string($value) && ($decoded = json_decode($value, true)) !== null && is_array($decoded)) {
                $value = $decoded;
            }
            if (is_array($value)) {
                if (! array_is_list($value)) {
                    ksort($value);
                }

                return array_map($canonical, $value);
            }

            return $value;
        };

        $payload = [
            'company_id' => $companyId, 'number' => (int) $a['number'], 'type' => $a['type'], 'subtype' => $a['subtype'],
            'patient_id' => $a['patient_id'], 'doctor_id' => $a['doctor_id'], 'verification_code' => $a['verification_code'],
            'issued_at' => substr((string) $a['issued_at'], 0, 19), 'valid_until' => $a['valid_until'] ? substr((string) $a['valid_until'], 0, 10) : null,
            'content' => $canonical($a['content']),
        ];

        return hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'documents|'.config('app.key'));
    }

    private function newCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 12; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while ($this->context->runAsSystem(fn () => MedicalDocument::query()->withoutGlobalScopes()->where('verification_code', $code)->exists()));

        return $code;
    }

    private function clean(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim(str_replace("\r\n", "\n", $value)) : null;

        return $value === null || $value === '' ? null : mb_substr($value, 0, $max);
    }
}
