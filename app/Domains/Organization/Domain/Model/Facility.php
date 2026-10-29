<?php

declare(strict_types=1);

namespace App\Domains\Organization\Domain\Model;

use App\Domains\Organization\Database\Factories\FacilityFactory;
use App\Domains\Organization\Domain\Enum\FacilityStatus;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The primary operational healthcare location
 * (docs/00-foundation/02-organization.md §6, ADR-003). `facility_type`,
 * `care_level`, `ownership_type` are separate string refs — none determines
 * workflow (ADR-004).
 */
class Facility extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<FacilityFactory> */
    use HasFactory;

    use HasUuids;

    protected static function newFactory(): FacilityFactory
    {
        return FacilityFactory::new();
    }

    protected $table = 'org_facilities';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'organization_id', 'name', 'code',
        'facility_type', 'care_level', 'ownership_type', 'status',
    ];

    protected $casts = ['status' => FacilityStatus::class];

    public function isActive(): bool
    {
        return $this->status === FacilityStatus::Active;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<Department, $this> */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /** @return HasMany<FacilityCapability, $this> */
    public function capabilities(): HasMany
    {
        return $this->hasMany(FacilityCapability::class);
    }
}
