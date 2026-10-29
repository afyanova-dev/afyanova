<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Model;

use App\Domains\HR\Contracts\Event\StaffOnboarded;
use App\Domains\HR\Contracts\Event\StaffReinstated;
use App\Domains\HR\Contracts\Event\StaffSuspended;
use App\Domains\HR\Contracts\Event\StaffTerminated;
use App\Domains\HR\Domain\Exception\InvalidStaffStatusTransitionException;
use App\Domains\HR\Domain\ValueObject\StaffStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Staff employment-facing aggregate root (25-hr-and-workforce-domain.md §2, §6, §8; ADR-206).
 *
 * Lifecycle: PROSPECT → ONBOARDING → ACTIVE ⇄ SUSPENDED → TERMINATED.
 *
 * @domain      HR
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    not-applicable
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $person_id
 * @property string $staff_number
 * @property StaffStatus $status
 * @property string|null $primary_cadre_id
 * @property string|null $primary_position_id
 * @property CarbonImmutable|null $joined_date
 * @property CarbonImmutable|null $terminated_date
 * @property string|null $termination_reason
 * @property string|null $suspension_reason
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Staff extends AggregateRoot
{
    protected $table = 'hr_staff';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'person_id',
        'staff_number',
        'status',
        'primary_cadre_id',
        'primary_position_id',
        'joined_date',
        'terminated_date',
        'termination_reason',
        'suspension_reason',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'status' => StaffStatus::class,
        'joined_date' => 'immutable_date',
        'terminated_date' => 'immutable_date',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function create(
        string $tenantId,
        string $personId,
        string $staffNumber,
        ?string $primaryCadreId = null,
        ?string $primaryPositionId = null,
    ): self {
        $staff = new self;
        $staff->tenant_id = $tenantId;
        $staff->person_id = $personId;
        $staff->staff_number = $staffNumber;
        $staff->status = StaffStatus::PROSPECT;
        $staff->primary_cadre_id = $primaryCadreId;
        $staff->primary_position_id = $primaryPositionId;

        return $staff;
    }

    public function onboard(
        CarbonImmutable $joinedDate,
        ?string $cadreId = null,
        ?string $positionId = null,
    ): void {
        if (! $this->status->canTransitionTo(StaffStatus::ACTIVE)) {
            throw InvalidStaffStatusTransitionException::transition($this->status, StaffStatus::ACTIVE);
        }

        $this->status = StaffStatus::ACTIVE;
        $this->joined_date = $joinedDate;

        if ($cadreId !== null) {
            $this->primary_cadre_id = $cadreId;
        }

        if ($positionId !== null) {
            $this->primary_position_id = $positionId;
        }

        $this->recordThat(new StaffOnboarded(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            staffId: $this->id,
            staffNumber: $this->staff_number,
            personId: $this->person_id,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function suspend(?string $reason = null): void
    {
        if (! $this->status->canTransitionTo(StaffStatus::SUSPENDED)) {
            throw InvalidStaffStatusTransitionException::transition($this->status, StaffStatus::SUSPENDED);
        }

        $this->status = StaffStatus::SUSPENDED;
        $this->suspension_reason = $reason;

        $this->recordThat(new StaffSuspended(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            staffId: $this->id,
            staffNumber: $this->staff_number,
            reason: $reason,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function reinstate(): void
    {
        if (! $this->status->canTransitionTo(StaffStatus::ACTIVE)) {
            throw InvalidStaffStatusTransitionException::transition($this->status, StaffStatus::ACTIVE);
        }

        $this->status = StaffStatus::ACTIVE;
        $this->suspension_reason = null;

        $this->recordThat(new StaffReinstated(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            staffId: $this->id,
            staffNumber: $this->staff_number,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function terminate(CarbonImmutable $terminatedDate, ?string $reason = null): void
    {
        if (! $this->status->canTransitionTo(StaffStatus::TERMINATED)) {
            throw InvalidStaffStatusTransitionException::transition($this->status, StaffStatus::TERMINATED);
        }

        $this->status = StaffStatus::TERMINATED;
        $this->terminated_date = $terminatedDate;
        $this->termination_reason = $reason;

        $this->recordThat(new StaffTerminated(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            staffId: $this->id,
            staffNumber: $this->staff_number,
            reason: $reason,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function setPrimaryCadreAndPosition(?string $cadreId, ?string $positionId): void
    {
        $this->primary_cadre_id = $cadreId;
        $this->primary_position_id = $positionId;
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    /**
     * @return BelongsTo<Cadre, $this>
     */
    public function primaryCadre(): BelongsTo
    {
        return $this->belongsTo(Cadre::class, 'primary_cadre_id');
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function primaryPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'primary_position_id');
    }

    /**
     * @return HasMany<Employment, $this>
     */
    public function employments(): HasMany
    {
        return $this->hasMany(Employment::class, 'staff_id');
    }
}
