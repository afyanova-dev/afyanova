<?php

declare(strict_types=1);

namespace App\Domains\Organization\Domain\Model;

use App\Domains\Organization\Domain\Enum\CapabilityState;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whether a facility operates a capability, and its lifecycle state
 * (docs/00-foundation/06-facility-levels-and-capabilities.md §11, §14, §60–§61).
 * Capability ≠ user authorization (ADR-006).
 */
class FacilityCapability extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'org_facility_capabilities';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'facility_id', 'capability_code', 'state', 'enabled_at', 'suspended_reason'];

    protected $casts = [
        'state' => CapabilityState::class,
        'enabled_at' => 'datetime',
    ];

    /** @return BelongsTo<Facility, $this> */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }
}
