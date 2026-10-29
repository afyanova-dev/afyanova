<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\Model;

use App\Domains\BedWard\Contracts\Event\BedReservationCancelled;
use App\Domains\BedWard\Contracts\Event\BedReservationExpired;
use App\Domains\BedWard\Contracts\Event\BedReservationFulfilled;
use App\Domains\BedWard\Contracts\Event\BedReservationHeld;
use App\Domains\BedWard\Contracts\Event\BedReservationRequested;
use App\Domains\BedWard\Database\Factories\BedReservationFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * BedReservation aggregate root (24-bed-and-ward-management-domain.md §6, §8.3;
 * ADR-149 D-A/D-B).
 *
 * Emits BedReservationRequested, BedReservationHeld, BedReservationFulfilled,
 * BedReservationCancelled, BedReservationExpired.
 *
 * @domain BedWard
 *
 * @authority authoritative
 *
 * @tenant required
 *
 * @facility required
 *
 * @property CarbonImmutable|null $hold_until
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 */
final class BedReservation extends AggregateRoot
{
    /** @use HasFactory<BedReservationFactory> */
    use HasFactory;

    public const STATUS_REQUESTED = 'REQUESTED';

    public const STATUS_HELD = 'HELD';

    public const STATUS_FULFILLED = 'FULFILLED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_EXPIRED = 'EXPIRED';

    protected $table = 'bw_bed_reservations';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'bed_id',
        'encounter_id',
        'patient_id',
        'requested_by_user_id',
        'reason',
        'hold_until',
        'status',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): BedReservationFactory
    {
        return BedReservationFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hold_until' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function request(
        string $id,
        string $tenantId,
        string $facilityId,
        string $bedId,
        ?string $encounterId,
        ?string $patientId,
        string $requestedByUserId,
        string $reason,
        CarbonImmutable $holdUntil,
    ): self {
        $now = CarbonImmutable::now();

        $reservation = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'bed_id' => $bedId,
            'encounter_id' => $encounterId,
            'patient_id' => $patientId,
            'requested_by_user_id' => $requestedByUserId,
            'reason' => $reason,
            'hold_until' => $holdUntil,
            'status' => self::STATUS_REQUESTED,
            'occurred_at' => $now,
            'recorded_at' => $now,
        ]);

        $reservation->recordThat(new BedReservationRequested(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            reservationId: $id,
            bedId: $bedId,
            encounterId: $encounterId,
            reason: $reason,
            holdUntil: $holdUntil->toIso8601String(),
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $reservation;
    }

    public function hold(): void
    {
        $this->status = self::STATUS_HELD;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new BedReservationHeld(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            reservationId: (string) $this->id,
            bedId: (string) $this->bed_id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function fulfill(string $occupancyId): void
    {
        $this->status = self::STATUS_FULFILLED;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new BedReservationFulfilled(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            reservationId: (string) $this->id,
            bedId: (string) $this->bed_id,
            occupancyId: $occupancyId,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new BedReservationCancelled(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            reservationId: (string) $this->id,
            bedId: (string) $this->bed_id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function expire(): void
    {
        $this->status = self::STATUS_EXPIRED;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new BedReservationExpired(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            reservationId: (string) $this->id,
            bedId: (string) $this->bed_id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function isExpired(): bool
    {
        return $this->hold_until !== null && $this->hold_until->isPast();
    }
}
