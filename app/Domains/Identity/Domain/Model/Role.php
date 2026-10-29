<?php

declare(strict_types=1);

namespace App\Domains\Identity\Domain\Model;

use App\Domains\Identity\Domain\Enum\AssignmentScopeType;
use App\Domains\Identity\Domain\Enum\RoleStatus;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A reusable permission bundle (docs/00-foundation/03-identity-rbac.md §8–§10,
 * §19, §35). `parent_role_id` inherits PERMISSIONS only — never scope (§19).
 */
class Role extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'idn_roles';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'facility_id', 'key', 'name', 'status', 'parent_role_id', 'scope_type'];

    protected $casts = [
        'status' => RoleStatus::class,
        'scope_type' => AssignmentScopeType::class,
        'is_protected' => 'boolean',
    ];

    /** @return BelongsTo<Role, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'parent_role_id');
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'idn_permission_role');
    }
}
