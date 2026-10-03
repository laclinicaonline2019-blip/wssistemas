<?php

namespace App\Modules\Documents\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Arquivo anexado ao paciente (resultado de exame, imagem, documento). Nunca excluído: apenas arquivado. */
class PatientFile extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    public const CATEGORIES = [
        'exam_result' => 'Resultado de exame', 'image' => 'Imagem / foto clínica', 'document' => 'Documento pessoal',
        'external_report' => 'Laudo / relatório externo', 'other' => 'Outro',
    ];

    public const MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    protected string $auditName = 'patient_file';

    protected array $auditExclude = ['path', 'disk', 'sha256'];

    protected $fillable = ['patient_id', 'encounter_id', 'category', 'title', 'original_name', 'mime', 'size_bytes', 'disk', 'path', 'sha256', 'scan_status', 'status', 'uploaded_by'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Arquivos do paciente não são excluídos — arquive.'));
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }

    public function sizeLabel(): string
    {
        return $this->size_bytes >= 1048576 ? number_format($this->size_bytes / 1048576, 1, ',', '.').' MB' : max(1, (int) round($this->size_bytes / 1024)).' KB';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
