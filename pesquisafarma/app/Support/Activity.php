<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/** Registra cada ação de televendas, farmácias e administradores. */
class Activity
{
    public static function log(string $action, string $description, ?Model $subject = null, array $meta = [], ?int $userId = null): void
    {
        ActivityLog::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => mb_substr($description, 0, 250),
            'meta' => $meta ?: null,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'created_at' => now(),
        ]);
    }
}
