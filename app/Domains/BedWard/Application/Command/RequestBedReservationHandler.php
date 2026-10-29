<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\BedRepository;
use App\Domains\BedWard\Domain\BedReservationRepository;
use App\Domains\BedWard\Domain\Exception\BedReservationConflict;
use App\Domains\BedWard\Domain\Model\BedReservation;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;

final class RequestBedReservationHandler
{
    public function __construct(
        private readonly BedRepository $beds,
        private readonly BedReservationRepository $reservations,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RequestBedReservation $command): void
    {
        $bed = $this->beds->getById($command->bedId);

        $reservation = BedReservation::request(
            id: $command->reservationId,
            tenantId: $this->context->tenantId(),
            facilityId: (string) $bed->facility_id,
            bedId: (string) $bed->id,
            encounterId: $command->encounterId,
            patientId: $command->patientId,
            requestedByUserId: $command->actorId,
            reason: $command->reason,
            holdUntil: $command->holdUntil,
        );

        try {
            $this->reservations->save($reservation);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'bw_bed_reservations_one_open_per_bed')) {
                throw BedReservationConflict::forBed($command->bedId);
            }

            throw $e;
        }
    }
}
