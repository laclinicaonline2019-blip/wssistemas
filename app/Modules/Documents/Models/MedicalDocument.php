<?php

namespace App\Modules\Documents\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Clinical\Models\Encounter;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Branch;
use App\Modules\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Documento médico emitido (receita, atestado, solicitação de exames, relatório…).
 * O conteúdo é imutável: só mudam situação (cancelamento), contadores de impressão
 * e dados de assinatura digital.
 */
class MedicalDocument extends Model
{
    use BelongsToCompany, HasUlids;

    public const TYPES = [
        'prescription' => 'Receita',
        'special_prescription' => 'Receita de controle especial',
        'notification_record' => 'Registro de notificação de receita',
        'certificate' => 'Atestado médico',
        'exam_request' => 'Solicitação de exames',
        'report' => 'Documento médico',
    ];

    public const REPORT_SUBTYPES = ['report' => 'Relatório médico', 'declaration' => 'Declaração', 'referral' => 'Encaminhamento'];

    public const CERTIFICATE_SUBTYPES = ['leave' => 'Afastamento', 'attendance' => 'Comparecimento'];

    /** Permissões por tipo: [emitir, imprimir (qualquer uma), cancelar]. */
    public const PERMISSIONS = [
        'prescription' => ['receita.emitir', ['receita.imprimir'], 'receita.cancelar'],
        'special_prescription' => ['receita.emitir', ['receita.imprimir'], 'receita.cancelar'],
        'notification_record' => ['receita.emitir', ['receita.imprimir'], 'receita.cancelar'],
        'certificate' => ['atestado.emitir', ['atestado.imprimir'], 'atestado.cancelar'],
        'exam_request' => ['exame.solicitar', ['exame.solicitar', 'documento.visualizar'], 'exame.solicitar'],
        'report' => ['prontuario.editar', ['prontuario.visualizar', 'documento.visualizar'], 'prontuario.editar'],
    ];

    /** Campos que podem mudar depois da emissão. */
    private const MUTABLE = ['status', 'cancelled_at', 'cancelled_by', 'cancel_reason', 'print_count', 'last_printed_at',
        'signature_status', 'signature_provider', 'signed_at', 'signature_reference', 'updated_at'];

    protected $fillable = [
        'branch_id', 'patient_id', 'doctor_id', 'encounter_id', 'group_id', 'number', 'type', 'subtype', 'content',
        'content_hash', 'verification_code', 'issued_at', 'valid_until', 'issued_by',
    ];

    protected $attributes = ['status' => 'issued', 'print_count' => 0, 'signature_status' => 'none'];

    protected function casts(): array
    {
        return [
            'content' => 'array', 'issued_at' => 'datetime', 'valid_until' => 'date', 'cancelled_at' => 'datetime',
            'last_printed_at' => 'datetime', 'signed_at' => 'datetime', 'number' => 'integer', 'print_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (MedicalDocument $doc) {
            if ($changed = array_diff(array_keys($doc->getDirty()), self::MUTABLE)) {
                throw new LogicException('Documento emitido é imutável ('.implode(', ', $changed).'). Cancele e emita outro.');
            }
            if ($doc->getOriginal('status') === 'cancelled' && $doc->isDirty('status')) {
                throw new LogicException('Documento cancelado não pode ser reativado.');
            }
        });
        static::deleting(fn () => throw new LogicException('Documento médico não pode ser excluído — use o cancelamento.'));
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by')->withTrashed();
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'report' => self::REPORT_SUBTYPES[$this->subtype] ?? self::TYPES['report'],
            'certificate' => self::TYPES['certificate'].($this->subtype === 'attendance' ? ' de comparecimento' : ''),
            default => self::TYPES[$this->type] ?? $this->type,
        };
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function displayNumber(): string
    {
        return $this->issued_at->format('Y').'/'.str_pad((string) $this->number, 6, '0', STR_PAD_LEFT);
    }

    public function formattedCode(): string
    {
        return implode('-', str_split($this->verification_code, 4));
    }

    public static function permissionFor(string $type, string $action): string|array
    {
        return self::PERMISSIONS[$type][['issue' => 0, 'print' => 1, 'cancel' => 2][$action]];
    }

    /** Pode imprimir em bobina térmica? (controle especial exige formulário com identificação do comprador). */
    public function allowsThermal(): bool
    {
        return in_array($this->type, ['prescription', 'certificate', 'exam_request'], true);
    }
}
