<?php

namespace App\Modules\Messaging\Models;

use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Aviso interno para a equipe (central de notificações). */
class StaffNotification extends Model
{
    use BelongsToCompany, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['branch_id', 'permission', 'type', 'level', 'title', 'body', 'url'];

    protected $attributes = ['level' => 'info'];

    /** Avisos que o usuário pode ver: unidade permitida e permissão exigida. */
    public function scopeVisibleTo(Builder $q, User $user, ?array $allowedBranches): Builder
    {
        $perms = collect(config('permissions.modules'))->flatMap(fn ($m) => array_keys($m['permissions']))
            ->filter(fn ($p) => $user->hasPermission($p))->values()->all();

        return $q->when($allowedBranches !== null, fn ($w) => $w->where(fn ($x) => $x->whereNull('branch_id')->orWhereIn('branch_id', $allowedBranches)))
            ->where(fn ($w) => $w->whereNull('permission')->orWhereIn('permission', $perms));
    }

    public function scopeUnreadBy(Builder $q, User $user): Builder
    {
        return $q->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('staff_notification_reads')
            ->whereColumn('staff_notification_reads.notification_id', 'staff_notifications.id')->where('staff_notification_reads.user_id', $user->id));
    }
}
