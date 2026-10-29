<?php

declare(strict_types=1);

namespace App\Domains\Organization\Domain\Model;

use App\Domains\Organization\Domain\Enum\StructureStatus;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Organisational grouping within a facility (docs/00-foundation/02-organization.md §11, ADR-007). */
class Department extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'org_departments';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'facility_id', 'name', 'code', 'kind', 'status'];

    protected $casts = ['status' => StructureStatus::class];

    /** @return BelongsTo<Facility, $this> */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /** @return HasMany<Unit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }
}
