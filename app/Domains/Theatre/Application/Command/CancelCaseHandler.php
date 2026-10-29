<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Domains\Theatre\Domain\OperatingRoomRepository;
use App\Domains\Theatre\Domain\Service\SurgicalCaseLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;

/**
 * Orchestrates `CancelCase` (ADR-150 D-D). A case never reaches
 * `IN_PROGRESS` before cancellation, so an assigned room reverts directly
 * to `AVAILABLE` — no cleaning was ever needed.
 */
final class CancelCaseHandler
{
    public function __construct(
        private readonly SurgicalCaseRepository $cases,
        private readonly OperatingRoomRepository $rooms,
        private readonly SurgicalCaseLifecycle $lifecycle,
    ) {}

    public function __invoke(CancelCase $command): void
    {
        $case = $this->cases->getById($command->caseId);

        $this->lifecycle->assertCanCancel((string) $case->status);

        $room = $case->operating_room_id !== null ? $this->rooms->getById((string) $case->operating_room_id) : null;

        if ($room !== null && $room->status === OperatingRoom::STATUS_RESERVED) {
            $room->transitionStatus(OperatingRoom::STATUS_AVAILABLE);
        }

        $case->cancel();

        $this->cases->save($case);

        if ($room !== null) {
            $this->rooms->save($room);
        }
    }
}
