<?php

namespace App\Modules\Documents\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Security\FileScanner;
use App\Core\Support\BusinessRuleViolation;
use App\Core\Tenancy\TenantContext;
use App\Modules\Clinical\Models\Encounter;
use App\Modules\Documents\Models\PatientFile;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Arquivos do paciente em disco privado (fora da pasta pública), um diretório por
 * empresa/paciente, nome aleatório e tipo detectado pelo conteúdo (não pela extensão).
 * Respeita o limite de armazenamento do plano. Download sempre pelo sistema (auditado).
 */
class PatientFileService
{
    public const DISK = 'local';

    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    public function store(User $actor, Patient $patient, UploadedFile $file, array $data): PatientFile
    {
        // Tipo detectado pelo CONTEÚDO do arquivo (assinatura), nunca pelo nome/extensão.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: '';

        if (! isset(PatientFile::MIMES[$mime])) {
            throw new BusinessRuleViolation('Formato não permitido. Envie PDF, JPG, PNG ou WEBP.', 'invalid_file_type');
        }

        // Fase 17: antivírus (se houver) + verificações próprias (PDF com script, imagem com código, EICAR).
        $scan = app(FileScanner::class)->assertSafe((string) file_get_contents($file->getRealPath()), $mime, 'patient_file');

        if (! empty($data['encounter_id']) && ! Encounter::query()->whereKey($data['encounter_id'])->where('patient_id', $patient->id)->exists()) {
            throw new BusinessRuleViolation('Atendimento inválido para este paciente.', 'invalid_encounter');
        }

        $companyId = $this->context->companyId();
        $path = "companies/{$companyId}/patients/{$patient->id}/".Str::ulid().'.'.PatientFile::MIMES[$mime];

        return DB::transaction(function () use ($actor, $patient, $file, $data, $mime, $companyId, $path, $scan) {
            $this->ensureStorage($companyId, $file->getSize());

            Storage::disk(self::DISK)->putFileAs(dirname($path), $file, basename($path));

            try {
                return PatientFile::create([
                    'patient_id' => $patient->id, 'encounter_id' => $data['encounter_id'] ?? null,
                    'category' => $data['category'], 'title' => trim($data['title'] ?? '') ?: mb_substr($file->getClientOriginalName(), 0, 150),
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 191), 'mime' => $mime, 'size_bytes' => $file->getSize(),
                    'disk' => self::DISK, 'path' => $path, 'sha256' => hash_file('sha256', $file->getRealPath()), 'scan_status' => $scan, 'uploaded_by' => $actor->id,
                ]);
            } catch (\Throwable $e) {
                Storage::disk(self::DISK)->delete($path); // não deixa arquivo órfão se o registro falhar

                throw $e;
            }
        });
    }

    public function recordDownload(PatientFile $file): void
    {
        $this->audit->record('patient_file.downloaded', $file, metadata: ['patient_id' => $file->patient_id]);
    }

    /** @return array{used_mb: float, limit_mb: ?int} */
    public function usage(string $companyId): array
    {
        $company = Company::query()->findOrFail($companyId);

        return [
            'used_mb' => round(DB::table('patient_files')->where('company_id', $companyId)->sum('size_bytes') / 1048576, 1),
            'limit_mb' => $company->plan?->limit('storage_mb') !== null ? (int) $company->plan->limit('storage_mb') : null,
        ];
    }

    private function ensureStorage(string $companyId, int $bytes): void
    {
        $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
        $limit = $company->plan?->limit('storage_mb');

        if ($limit !== null && DB::table('patient_files')->where('company_id', $companyId)->sum('size_bytes') + $bytes > (int) $limit * 1048576) {
            throw new BusinessRuleViolation("Limite de armazenamento do plano atingido ({$limit} MB). Faça upgrade do plano.", 'plan_limit_reached');
        }
    }
}
