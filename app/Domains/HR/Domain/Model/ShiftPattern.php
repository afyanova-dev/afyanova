<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Model;

use App\Domains\HR\Contracts\Event\ShiftPatternDefined;
use App\Domains\HR\Contracts\Event\ShiftPatternUpdated;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Reusable shift pattern template aggregate root (25-hr-and-workforce-domain.md §6; ADR-206).
 *
 * @domain      HR
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $code
 * @property string $name
 * @property string $start_time
 * @property string $end_time
 * @property int $break_minutes
 * @property bool $is_overnight
 * @property bool $is_active
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ShiftPattern extends AggregateRoot
{
    protected $table = 'hr_shift_patterns';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'start_time',
        'end_time',
        'break_minutes',
        'is_overnight',
        'is_active',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'break_minutes' => 'integer',
        'is_overnight' => 'boolean',
        'is_active' => 'boolean',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function create(
        string $tenantId,
        string $facilityId,
        string $code,
        string $name,
        string $startTime,
        string $endTime,
        int $breakMinutes = 0,
        bool $isOvernight = false,
        ?string $shiftPatternId = null,
    ): self {
        $id = $shiftPatternId ?? (string) Str::uuid();

        $pattern = new self([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'code' => strtoupper(trim($code)),
            'name' => trim($name),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'break_minutes' => $breakMinutes,
            'is_overnight' => $isOvernight,
            'is_active' => true,
            'version' => 0,
        ]);

        $pattern->id = $id;

        $pattern->recordThat(new ShiftPatternDefined(
            eventId: (string) Str::uuid(),
            tenantId: $tenantId,
            shiftPatternId: $id,
            facilityId: $facilityId,
            code: $pattern->code,
            name: $pattern->name,
            startTime: $pattern->start_time,
            endTime: $pattern->end_time,
            breakDurationMinutes: $pattern->break_minutes,
            isOvernight: $pattern->is_overnight,
            colorCode: null,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));

        return $pattern;
    }

    public function updateDetails(
        string $name,
        string $startTime,
        string $endTime,
        int $breakMinutes,
        bool $isOvernight,
        bool $isActive = true,
    ): void {
        $this->name = trim($name);
        $this->start_time = $startTime;
        $this->end_time = $endTime;
        $this->break_minutes = $breakMinutes;
        $this->is_overnight = $isOvernight;
        $this->is_active = $isActive;

        $this->recordThat(new ShiftPatternUpdated(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            shiftPatternId: $this->id,
            facilityId: $this->facility_id,
            code: $this->code,
            name: $this->name,
            startTime: $this->start_time,
            endTime: $this->end_time,
            breakDurationMinutes: $this->break_minutes,
            isOvernight: $this->is_overnight,
            colorCode: null,
            isActive: $this->is_active,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    /**
     * @return HasMany<RosterEntry, $this>
     */
    public function rosterEntries(): HasMany
    {
        return $this->hasMany(RosterEntry::class, 'shift_pattern_id');
    }
}
