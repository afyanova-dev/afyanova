<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Domains\Theatre\Domain\OperatingRoomRepository;
use App\Domains\Theatre\Domain\Service\SurgicalCaseLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;

/**
 * Orchestrates `AbortCase` (ADR-150 D-D). The case DID reach `IN_PROGRESS`,
 * so the room follows the same `IN_USE` -> `CLEANING` path as `CompleteCase`
 * (the procedure started; the room needs turnover regardless of outcome).
 */
final class AbortCaseHandler
{
    public function __construct(
        private readonly SurgicalCaseRepository $cases,
        private readonly OperatingRoomRepository $rooms,
        private readonly SurgicalCaseLifecycle $lifecycle,
    ) {}

    public function __invoke(AbortCase $command): void
    {
        $case = $this->cases->getById($command->caseId);

        $this->lifecycle->assertCanAbort((string) $case->status);

        $room = $case->operating_room_id !== null ? $this->rooms->getById((string) $case->operating_room_id) : null;

        if ($room !== null) {
            $this->lifecycle->assertRoomCanTransition((string) $room->status, OperatingRoom::STATUS_CLEANING);
            $room->transitionStatus(OperatingRoom::STATUS_CLEANING);
        }

        $case->abort();

        $this->cases->save($case);

        if ($room !== null) {
            $this->rooms->save($room);
        }
    }
}
