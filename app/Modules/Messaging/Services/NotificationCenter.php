<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\StaffNotification;

/** Avisos internos para a equipe (sino no topo da tela). */
class NotificationCenter
{
    public function notify(string $type, string $title, ?string $body = null, ?string $url = null, ?string $branchId = null, ?string $permission = null, string $level = 'info'): StaffNotification
    {
        return StaffNotification::create([
            'type' => $type, 'title' => mb_substr($title, 0, 160), 'body' => $body ? mb_substr($body, 0, 500) : null,
            'url' => $url, 'branch_id' => $branchId, 'permission' => $permission, 'level' => $level,
        ]);
    }
}
