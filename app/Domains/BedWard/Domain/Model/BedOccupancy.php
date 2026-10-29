<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\Model;

use App\Domains\BedWard\Contracts\Event\BedAssigned;
use App\Domains\BedWard\Contracts\Event\BedReleased;
use App\Domains\BedWard\Contracts\Event\OccupancyCorrected;
use App\Domains\BedWard\Contracts\Event\PatientTransferredBed;
use App\Domains\BedWard\Database\Factories\BedOccupancyFactory;
use App\Domains\BedWard\Domain\ValueObject\BedEndReason;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * BedOccupancy aggregate root (24-bed-and-ward-management-domain.md §6;
 * ADR-147 D-A/D-B, ADR-148 D-A/D-B).
 *
 * Emits BedAssigned, BedReleased, PatientTransferredBed, OccupancyCorrected.
 *
 * @domain BedWard
 *
 * @authority authoritative
 *
 * @tenant required
 *
 * @facility required
 *
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $recorded_at
 */
final class BedOccupancy extends AggregateRoot
{
    /** @use HasFactory<BedOccupancyFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_ENDED = 'ENDED';

    /** @var list<string> Fields mutable via CorrectOccupancy */
    public const CORRECTABLE_FIELDS = [
        'occurred_at',
        'ended_at',
        'end_reason',
        'bed_id',
    ];

    protected $table = 'bw_bed_occupancies';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'bed_id',
        'encounter_id',
        'patient_id',
        'assigned_by_user_id',
        'status',
        'end_reason',
        'occurred_at',
        'ended_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): BedOccupancyFactory
    {
        return BedOccupancyFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function assign(
        string $id,
        string $tenantId,
        string $facilityId,
        string $bedId,
        string $encounterId,
        string $patientId,
        string $assignedByUserId,
        ?CarbonImmutable $occurredAt = null,
    ): self {
        $occurred = $occurredAt ?? CarbonImmutable::now();
        $recorded = CarbonImmutable::now();

        $occupancy = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'bed_id' => $bedId,
            'encounter_id' => $encounterId,
            'patient_id' => $patientId,
            'assigned_by_user_id' => $assignedByUserId,
            'status' => self::STATUS_ACTIVE,
            'end_reason' => null,
            'occurred_at' => $occurred,
            'ended_at' => null,
            'recorded_at' => $recorded,
        ]);

        $now = CarbonImmutable::now()->toIso8601String();

        $occupancy->recordThat(new BedAssigned(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            bedId: $bedId,
            encounterId: $encounterId,
            occupancyId: $id,
            startedAt: $occurred->toIso8601String(),
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $occupancy;
    }

    public function release(string $endReason, ?CarbonImmutable $endedAt = null): void
    {
        $validatedReason = BedEndReason::assert($endReason);
        $ended = $endedAt ?? CarbonImmutable::now();

        $this->status = self::STATUS_ENDED;
        $this->end_reason = $validatedReason;
        $this->ended_at = $ended;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new BedReleased(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            bedId: (string) $this->bed_id,
            encounterId: (string) $this->encounter_id,
            occupancyId: (string) $this->id,
            endedAt: $ended->toIso8601String(),
            endReason: $validatedReason,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function recordTransfer(string $toBedId, string $toOccupancyId): void
    {
        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new PatientTransferredBed(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            encounterId: (string) $this->encounter_id,
            fromBedId: (string) $this->bed_id,
            toBedId: $toBedId,
            fromOccupancyId: (string) $this->id,
            toOccupancyId: $toOccupancyId,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * Correct occupancy fields (bounded mutation, ADR-148 D-H).
     *
     * @param  array<string, mixed>  $changes
     * @return array{changedFields: list<string>, previous: array<string, mixed>, new: array<string, mixed>}
     */
    public function correct(array $changes): array
    {
        $changedFields = [];
        $previous = [];
        $new = [];

        foreach ($changes as $field => $val) {
            if (! in_array($field, self::CORRECTABLE_FIELDS, true)) {
                continue;
            }

            if ($field === 'end_reason' && $val !== null) {
                $val = BedEndReason::assert((string) $val);
            }

            $currentVal = $this->getAttribute($field);
            // Compare string representation for dates/strings
            $currentStr = $currentVal instanceof \DateTimeInterface ? $currentVal->format('c') : (string) $currentVal;
            $newStr = $val instanceof \DateTimeInterface ? $val->format('c') : (string) $val;

            if ($currentStr !== $newStr) {
                $previous[$field] = $currentStr;
                $new[$field] = $newStr;
                $changedFields[] = $field;
                $this->setAttribute($field, $val);
            }
        }

        if ($changedFields !== []) {
            $now = CarbonImmutable::now()->toIso8601String();

            $this->recordThat(new OccupancyCorrected(
                eventId: (string) Str::uuid7(),
                tenantId: (string) $this->tenant_id,
                facilityId: (string) $this->facility_id,
                occupancyId: (string) $this->id,
                changedFields: $changedFields,
                occurredAt: $now,
                correlationId: CorrelationId::get(),
                causationId: Causation::get(),
            ));
        }

        return [
            'changedFields' => $changedFields,
            'previous' => $previous,
            'new' => $new,
        ];
    }
}
