<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Exception\OperatingRoomFacilityMismatch;
use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Domains\Theatre\Domain\OperatingRoomRepository;
use App\Domains\Theatre\Domain\Service\SurgicalCaseLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;

/**
 * Orchestrates `AssignOperatingRoom` (ADR-150 D-C). `OperatingRoomRepository`
 * is injected FIRST so the bus resolves `OperatingRoomPolicy` for the
 * `AuthorizesAgainst` check (IC §5.1).
 */
final class AssignOperatingRoomHandler
{
    public function __construct(
        private readonly OperatingRoomRepository $rooms,
        private readonly SurgicalCaseRepository $cases,
        private readonly SurgicalCaseLifecycle $lifecycle,
    ) {}

    public function __invoke(AssignOperatingRoom $command): void
    {
        $room = $this->rooms->getById($command->operatingRoomId);
        $case = $this->cases->getById($command->caseId);

        if ((string) $room->facility_id !== (string) $case->facility_id) {
            throw OperatingRoomFacilityMismatch::forCase(
                (string) $case->id,
                (string) $case->facility_id,
                (string) $room->facility_id,
            );
        }

        $this->lifecycle->assertCanAssignRoom((string) $case->status, (string) $room->id, (string) $room->status);

        $case->assignOperatingRoom((string) $room->id);
        $room->transitionStatus(OperatingRoom::STATUS_RESERVED);

        $this->cases->save($case);
        $this->rooms->save($room);
    }
}
