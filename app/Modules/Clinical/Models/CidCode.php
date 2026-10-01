<?php

namespace App\Modules\Clinical\Models;

use App\Core\Support\Format;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Código CID (catálogo global da plataforma). */
class CidCode extends Model
{
    use HasUlids;

    protected $fillable = ['version', 'code', 'description', 'sex_restriction', 'is_active', 'is_sample'];

    protected $attributes = ['version' => 'CID-10', 'is_active' => true, 'is_sample' => false];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_sample' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (CidCode $cid) {
            $cid->code = strtoupper(trim($cid->code));
            $cid->search_text = Format::searchable($cid->code.' '.str_replace('.', '', $cid->code).' '.$cid->description);
        });
    }

    /** Busca por código ("I10", "i1") ou por palavras da descrição, sem acento. */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = Format::searchable($term);
        $words = array_filter(explode(' ', $term), fn ($w) => mb_strlen($w) >= 2);

        return $query->where('is_active', true)->where(function (Builder $q) use ($term, $words) {
            $q->where('code', 'like', strtoupper(addcslashes($term, '%_\\')).'%');
            if ($words) {
                $q->orWhere(function (Builder $w) use ($words) {
                    foreach ($words as $word) {
                        $w->where('search_text', 'like', '%'.addcslashes($word, '%_\\').'%');
                    }
                });
            }
        });
    }

    public function label(): string
    {
        return "{$this->code} — {$this->description}";
    }
}
