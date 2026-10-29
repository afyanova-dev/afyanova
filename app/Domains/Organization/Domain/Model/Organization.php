<?php

declare(strict_types=1);

namespace App\Domains\Organization\Domain\Model;

use App\Domains\Organization\Database\Factories\OrganizationFactory;
use App\Domains\Organization\Domain\Enum\OrganizationStatus;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The business entity operating facilities within a tenant
 * (docs/00-foundation/02-organization.md §4, ADR-002). PHASE 3: a plain
 * tenant-scoped model; PHASE 4 promotes it to an AggregateRoot.
 */
class Organization extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUuids;

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }

    protected $table = 'org_organizations';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'name', 'code', 'status'];

    protected $casts = ['status' => OrganizationStatus::class];

    /** @return HasMany<Facility, $this> */
    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class);
    }
}
