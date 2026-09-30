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
    private static bool $auditingSuspended = false;

    /**
     * Executa sem a auditoria automática deste model. Uso restrito a operações
     * que registram um evento próprio sem copiar dados pessoais (ex.: anonimização LGPD).
     */
    public static function withoutAuditing(callable $callback): mixed
    {
        $previous = self::$auditingSuspended;
        self::$auditingSuspended = true;

        try {
            return $callback();
        } finally {
            self::$auditingSuspended = $previous;
        }
    }

    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            if (self::$auditingSuspended) {
                return;
            }

            app(AuditLogger::class)->record(
                $model->auditType().'.created',
                $model,
                new: $model->auditableAttributes($model->getAttributes()),
            );
        });

        static::updated(function (Model $model) {
            if (self::$auditingSuspended) {
                return;
            }

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
            if (self::$auditingSuspended) {
                return;
            }

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
