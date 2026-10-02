<?php

namespace App\Modules\Insurance\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Procedimento faturável (código TUSS — tabela 22 — ou tabela própria da operadora "00"). */
class Procedure extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    /** Tipo do procedimento → tipoAtendimento da guia SP-SADT (TISS 4.01). */
    public const KINDS = [
        'consultation' => ['label' => 'Consulta', 'attendance_type' => '04'],
        'exam' => ['label' => 'Exame', 'attendance_type' => '23'],
        'therapy' => ['label' => 'Terapia', 'attendance_type' => '03'],
        'minor_surgery' => ['label' => 'Pequena cirurgia', 'attendance_type' => '02'],
        'small_care' => ['label' => 'Pequeno atendimento', 'attendance_type' => '13'],
    ];

    public const TABLES = ['22' => 'TUSS — procedimentos', '00' => 'Tabela própria da operadora', '98' => 'Tabela própria de pacotes', '90' => 'Tabela própria — Odonto'];

    protected string $auditName = 'procedure';

    protected $fillable = ['table_code', 'code', 'name', 'kind', 'is_active', 'is_sample'];

    protected $attributes = ['is_active' => true, 'is_sample' => false, 'table_code' => '22', 'kind' => 'consultation'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_sample' => 'boolean'];
    }

    public function label(): string
    {
        return $this->code.' — '.$this->name;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind]['label'] ?? $this->kind;
    }
}
