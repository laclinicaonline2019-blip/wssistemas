<?php

namespace App\Modules\Identity\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToCompany;
use App\Modules\Organization\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Vínculo usuário ↔ perfil, opcionalmente restrito a uma filial. */
class RoleAssignment extends Model
{
    use Auditable, BelongsToCompany, HasUlids;

    protected $table = 'user_role_assignments';

    protected string $auditName = 'role_assignment';

    protected $fillable = ['user_id', 'role_id', 'branch_id', 'assigned_by'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
