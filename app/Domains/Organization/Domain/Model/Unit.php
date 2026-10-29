<?php

declare(strict_types=1);

namespace App\Domains\Organization\Domain\Model;

use App\Domains\Organization\Domain\Enum\StructureStatus;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A specific execution area inside a department (docs/00-foundation/02-organization.md §13). Generic by design (ADR-007). */
class Unit extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'org_units';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'facility_id', 'department_id', 'name', 'code', 'unit_type', 'status'];

    protected $casts = ['status' => StructureStatus::class];

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
