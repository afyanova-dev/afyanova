<?php

declare(strict_types=1);

namespace App\Domains\Organization\Domain\Model;

use App\Domains\Organization\Domain\Enum\StructureStatus;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The physical-space master record (docs/00-foundation/02-organization.md §14,
 * ADR-111). Bed occupancy, OR state, schedulable availability and equipment
 * assets are OTHER domains' — they reference a room by id.
 */
class Room extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'org_rooms';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'facility_id', 'unit_id', 'name', 'code', 'room_type', 'status'];

    protected $casts = ['status' => StructureStatus::class];

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
