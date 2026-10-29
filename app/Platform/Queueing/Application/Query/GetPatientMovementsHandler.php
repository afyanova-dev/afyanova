<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Query;

use App\Platform\Queueing\Contracts\DTO\PatientMovementDto;
use App\Platform\Queueing\Domain\Model\PatientMovement;
use App\Platform\Queueing\Domain\Repository\PatientMovementRepository;

final readonly class GetPatientMovementsHandler
{
    public function __construct(
        private PatientMovementRepository $movementRepository,
    ) {}

    /**
     * @return list<PatientMovementDto>
     */
    public function __invoke(GetPatientMovementsQuery $query): array
    {
        $movements = $this->movementRepository->listForEncounter($query->encounterId);

        return array_map(
            fn (PatientMovement $m): PatientMovementDto => new PatientMovementDto(
                id: (string) $m->id,
                encounterId: $m->encounter_id,
                patientId: $m->patient_id,
                fromArea: $m->from_area,
                toArea: $m->to_area,
                status: $m->status,
                queueEntryId: $m->queue_entry_id,
                actorId: $m->actor_id,
                reason: $m->reason,
                occurredAt: $m->occurred_at,
            ),
            $movements
        );
    }
}
