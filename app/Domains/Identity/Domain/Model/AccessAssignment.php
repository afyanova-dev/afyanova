<?php

declare(strict_types=1);

namespace App\Domains\Identity\Domain\Model;

use App\Domains\Identity\Domain\Enum\AssignmentStatus;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This user holds this role in this scope" (docs/00-foundation/03-identity-rbac.md
 * §6, §37–§38). Distinct from the HR Assignment (docs/01-domains/25 §7.5).
 * NULL at a scope level = "all at/below that level".
 */
class AccessAssignment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'idn_access_assignments';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'facility_id', 'user_id', 'role_id',
        'organization_id', 'department_id', 'unit_id',
        'status', 'effective_from', 'effective_to', 'is_primary', 'granted_by',
    ];

    protected $casts = [
        'status' => AssignmentStatus::class,
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'is_primary' => 'boolean',
    ];

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** Currently in force: ACTIVE and (if dated) within the window. */
    public function isEffective(): bool
    {
        if ($this->status !== AssignmentStatus::Active) {
            return false;
        }

        $now = now();

        return ($this->effective_from === null || $this->effective_from->lessThanOrEqualTo($now))
            && ($this->effective_to === null || $this->effective_to->greaterThanOrEqualTo($now));
    }
}
