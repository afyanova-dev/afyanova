<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\BedWard\Contracts\BedWardApi;
use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Domains\Theatre\Domain\OperatingRoomRepository;
use App\Domains\Theatre\Domain\Service\SurgicalCaseLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;
use Illuminate\Support\Str;

/**
 * Orchestrates `CompleteCase` (ADR-150 D-D/D-H). See `CompleteCase`'s own
 * docblock for the recovery-handoff dispatch — via `BedWardApi::assignBed`
 * (IC §2.1: a module imports another module only through its `Contracts`
 * namespace; dispatching `BedWard`'s internal `AssignBed` command directly
 * would violate that boundary, found by the arch test during
 * implementation).
 */
final class CompleteCaseHandler
{
    public function __construct(
        private readonly SurgicalCaseRepository $cases,
        private readonly OperatingRoomRepository $rooms,
        private readonly SurgicalCaseLifecycle $lifecycle,
        private readonly BedWardApi $bedWard,
    ) {}

    public function __invoke(CompleteCase $command): void
    {
        $case = $this->cases->getById($command->caseId);

        $this->lifecycle->assertCanComplete((string) $case->status);

        $room = $case->operating_room_id !== null ? $this->rooms->getById((string) $case->operating_room_id) : null;

        if ($room !== null) {
            $this->lifecycle->assertRoomCanTransition((string) $room->status, OperatingRoom::STATUS_CLEANING);
        }

        $case->complete(
            actualProcedure: $command->actualProcedure,
            findings: $command->findings,
            outcome: $command->outcome,
            complications: $command->complications,
            recoveryDestinationType: $command->recoveryDestinationType,
        );

        $room?->transitionStatus(OperatingRoom::STATUS_CLEANING);

        $this->cases->save($case);

        if ($room !== null) {
            $this->rooms->save($room);
        }

        if ($command->recoveryBedId !== null) {
            $this->bedWard->assignBed(
                occupancyId: (string) Str::uuid7(),
                encounterId: (string) $case->encounter_id,
                bedId: $command->recoveryBedId,
                actorId: $command->actorId,
            );
        }
    }
}
