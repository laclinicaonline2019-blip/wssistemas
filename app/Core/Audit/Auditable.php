<?php

namespace App\Core\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Auditoria automática de criação, alteração e exclusão de models.
 *
 * O model pode definir:
 *  - auditType(): string                nome lógico (ex.: "branch")
 *  - $auditExclude: list<string>        atributos ignorados
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            app(AuditLogger::class)->record(
                $model->auditType().'.created',
                $model,
                new: $model->auditableAttributes($model->getAttributes()),
            );
        });

        static::updated(function (Model $model) {
            $changes = $model->auditableAttributes($model->getChanges());

            if ($changes === []) {
                return;
            }

            $old = array_intersect_key($model->getOriginal(), $changes);

            app(AuditLogger::class)->record(
                $model->auditType().'.updated',
                $model,
                old: $model->auditableAttributes($old),
                new: $changes,
            );
        });

        static::deleted(function (Model $model) {
            app(AuditLogger::class)->record(
                $model->auditType().'.deleted',
                $model,
                old: $model->auditableAttributes($model->getOriginal()),
            );
        });
    }

    public function auditType(): string
    {
        return property_exists($this, 'auditName') ? $this->auditName : strtolower(class_basename($this));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditableAttributes(array $attributes): array
    {
        $exclude = array_merge(['created_at', 'updated_at'], property_exists($this, 'auditExclude') ? $this->auditExclude : []);

        $attributes = array_diff_key($attributes, array_flip($exclude));

        foreach ($this->getHidden() as $hidden) {
            if (array_key_exists($hidden, $attributes)) {
                $attributes[$hidden] = AuditLogger::REDACTED;
            }
        }

        return array_map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value, $attributes);
    }
}
