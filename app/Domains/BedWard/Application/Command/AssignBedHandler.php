<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\BedOccupancyRepository;
use App\Domains\BedWard\Domain\BedRepository;
use App\Domains\BedWard\Domain\BedReservationRepository;
use App\Domains\BedWard\Domain\Exception\BedAlreadyOccupied;
use App\Domains\BedWard\Domain\Exception\BedNotAvailable;
use App\Domains\BedWard\Domain\Exception\EncounterAlreadyHasActiveOccupancy;
use App\Domains\BedWard\Domain\Exception\EncounterNotEligibleForBedAssignment;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Domains\BedWard\Domain\Model\BedReservation;
use App\Domains\BedWard\Domain\Service\BedLifecycle;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * ADR-149 D-E extends this handler (existing B.1 code, not new B.2-only
 * surface): a RESERVED bed with a HELD reservation matching the target
 * encounter is fulfilled atomically as part of assigning, rather than
 * requiring a separate caller-invoked fulfill step. A lapsed hold is
 * self-healed (expired, bed reverted to AVAILABLE) before the normal flow
 * continues (ADR-149 D-C).
 */
final class AssignBedHandler
{
    public function __construct(
        private readonly BedRepository $beds,
        private readonly BedOccupancyRepository $occupancies,
        private readonly BedReservationRepository $reservations,
        private readonly EncounterApi $encounters,
        private readonly BedLifecycle $lifecycle,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(AssignBed $command): void
    {
        $bed = $this->beds->getById($command->bedId);
        $matchedReservation = null;

        if ($bed->status === Bed::STATUS_RESERVED) {
            $matchedReservation = $this->resolveReservedBed($bed, $command->encounterId);
        }

        if ($bed->status !== Bed::STATUS_AVAILABLE && $matchedReservation === null) {
            throw BedNotAvailable::forStatus((string) $bed->id, (string) $bed->status);
        }

        if ($matchedReservation === null) {
            $this->lifecycle->assertCanAssign((string) $bed->status);
        }

        $encounterCtx = $this->encounters->getEncounterContext($command->encounterId);

        if ($encounterCtx->status !== 'ACTIVE') {
            throw EncounterNotEligibleForBedAssignment::status($encounterCtx->encounterId, $encounterCtx->status);
        }

        if (! in_array($encounterCtx->encounterType, ['INPATIENT', 'MATERNITY'], true)) {
            throw EncounterNotEligibleForBedAssignment::type($encounterCtx->encounterId, $encounterCtx->encounterType);
        }

        if ($encounterCtx->facilityId !== (string) $bed->facility_id) {
            throw EncounterNotEligibleForBedAssignment::facilityMismatch(
                $encounterCtx->encounterId,
                $encounterCtx->facilityId,
                (string) $bed->facility_id,
            );
        }

        $tenantId = $this->context->tenantId();

        $occupancy = BedOccupancy::assign(
            id: $command->occupancyId,
            tenantId: $tenantId,
            facilityId: (string) $bed->facility_id,
            bedId: (string) $bed->id,
            encounterId: $command->encounterId,
            patientId: $encounterCtx->patientId,
            assignedByUserId: $command->actorId,
            occurredAt: $command->occurredAt,
        );

        $bed->transitionStatus(Bed::STATUS_OCCUPIED);

        $matchedReservation?->fulfill((string) $occupancy->id);

        try {
            $this->occupancies->save($occupancy);
            $this->beds->save($bed);

            if ($matchedReservation !== null) {
                $this->reservations->save($matchedReservation);
            }
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'bw_bed_occupancies_one_active_per_bed')) {
                throw BedAlreadyOccupied::forBed($command->bedId);
            }

            if (str_contains($e->getMessage(), 'bw_bed_occupancies_one_active_per_encounter')) {
                throw EncounterAlreadyHasActiveOccupancy::forEncounter($command->encounterId);
            }

            throw $e;
        }
    }

    /**
     * A RESERVED bed either has a stale (lapsed hold_until) reservation —
     * self-healed here, bed reverted to AVAILABLE and $bed mutated in place —
     * or a live HELD reservation, which must match the assigning encounter to
     * be fulfilled. Returns the matched HELD reservation, or null once the
     * bed has been self-healed back to AVAILABLE.
     */
    private function resolveReservedBed(Bed $bed, string $encounterId): ?BedReservation
    {
        $reservation = $this->reservations->getOpenForBed((string) $bed->id);

        if ($reservation !== null && $reservation->isExpired()) {
            $reservation->expire();
            $this->reservations->save($reservation);

            $bed->transitionStatus(Bed::STATUS_AVAILABLE);
            $this->beds->save($bed);

            return null;
        }

        if ($reservation === null || $reservation->status !== BedReservation::STATUS_HELD) {
            // RESERVED bed with no live HELD reservation — a data
            // inconsistency this handler does not attempt to repair; the
            // bed's own status is authoritative and the caller is rejected.
            return null;
        }

        if ((string) $reservation->encounter_id !== $encounterId) {
            throw BedNotAvailable::forStatus((string) $bed->id, (string) $bed->status);
        }

        return $reservation;
    }
}
