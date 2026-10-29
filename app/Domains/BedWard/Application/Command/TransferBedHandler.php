<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\BedOccupancyRepository;
use App\Domains\BedWard\Domain\BedRepository;
use App\Domains\BedWard\Domain\Exception\BedAlreadyOccupied;
use App\Domains\BedWard\Domain\Exception\BedNotAvailable;
use App\Domains\BedWard\Domain\Exception\EncounterAlreadyHasActiveOccupancy;
use App\Domains\BedWard\Domain\Exception\EncounterNotEligibleForBedAssignment;
use App\Domains\BedWard\Domain\Exception\IllegalOccupancyTransition;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Domains\BedWard\Domain\Service\BedLifecycle;
use App\Domains\BedWard\Domain\ValueObject\BedEndReason;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

final class TransferBedHandler
{
    public function __construct(
        private readonly BedRepository $beds,
        private readonly BedOccupancyRepository $occupancies,
        private readonly EncounterApi $encounters,
        private readonly BedLifecycle $lifecycle,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(TransferBed $command): void
    {
        $toBed = $this->beds->getById($command->toBedId);

        if ($toBed->status !== Bed::STATUS_AVAILABLE) {
            throw BedNotAvailable::forStatus((string) $toBed->id, (string) $toBed->status);
        }

        $fromBed = $this->beds->getById($command->fromBedId);

        $currentOccupancy = $this->occupancies->getActiveForEncounter($command->encounterId)
            ?? throw NotFound::aggregate(BedOccupancy::class, $command->encounterId);

        if ((string) $currentOccupancy->bed_id !== $command->fromBedId) {
            throw new IllegalOccupancyTransition(sprintf(
                'Active occupancy [%s] for encounter [%s] is in bed [%s], not fromBed [%s].',
                $currentOccupancy->id,
                $command->encounterId,
                $currentOccupancy->bed_id,
                $command->fromBedId,
            ));
        }

        $this->lifecycle->assertCanTransfer(
            (string) $currentOccupancy->status,
            (string) $fromBed->status,
            (string) $toBed->status,
        );

        $encounterCtx = $this->encounters->getEncounterContext($command->encounterId);

        if ($encounterCtx->status !== 'ACTIVE') {
            throw EncounterNotEligibleForBedAssignment::status($encounterCtx->encounterId, $encounterCtx->status);
        }

        if (! in_array($encounterCtx->encounterType, ['INPATIENT', 'MATERNITY'], true)) {
            throw EncounterNotEligibleForBedAssignment::type($encounterCtx->encounterId, $encounterCtx->encounterType);
        }

        if ($encounterCtx->facilityId !== (string) $toBed->facility_id) {
            throw EncounterNotEligibleForBedAssignment::facilityMismatch(
                $encounterCtx->encounterId,
                $encounterCtx->facilityId,
                (string) $toBed->facility_id,
            );
        }

        $transferredAt = $command->transferredAt ?? CarbonImmutable::now();
        $tenantId = $this->context->tenantId();

        // 1. End current occupancy
        $currentOccupancy->release(BedEndReason::TRANSFER, $transferredAt);
        $currentOccupancy->recordTransfer((string) $toBed->id, $command->newOccupancyId);
        $fromBed->transitionStatus(Bed::STATUS_CLEANING);

        // 2. Start new occupancy
        $newOccupancy = BedOccupancy::assign(
            id: $command->newOccupancyId,
            tenantId: $tenantId,
            facilityId: (string) $toBed->facility_id,
            bedId: (string) $toBed->id,
            encounterId: $command->encounterId,
            patientId: $encounterCtx->patientId,
            assignedByUserId: $command->actorId,
            occurredAt: $transferredAt,
        );

        $toBed->transitionStatus(Bed::STATUS_OCCUPIED);

        try {
            $this->occupancies->save($currentOccupancy);
            $this->beds->save($fromBed);
            $this->occupancies->save($newOccupancy);
            $this->beds->save($toBed);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'bw_bed_occupancies_one_active_per_bed')) {
                throw BedAlreadyOccupied::forBed($command->toBedId);
            }

            if (str_contains($e->getMessage(), 'bw_bed_occupancies_one_active_per_encounter')) {
                throw EncounterAlreadyHasActiveOccupancy::forEncounter($command->encounterId);
            }

            throw $e;
        }
    }
}
