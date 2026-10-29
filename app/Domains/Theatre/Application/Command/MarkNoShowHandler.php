<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Domains\Theatre\Domain\OperatingRoomRepository;
use App\Domains\Theatre\Domain\Service\SurgicalCaseLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;

final class MarkNoShowHandler
{
    public function __construct(
        private readonly SurgicalCaseRepository $cases,
        private readonly OperatingRoomRepository $rooms,
        private readonly SurgicalCaseLifecycle $lifecycle,
    ) {}

    public function __invoke(MarkNoShow $command): void
    {
        $case = $this->cases->getById($command->caseId);

        $this->lifecycle->assertCanMarkNoShow((string) $case->status);

        $room = $case->operating_room_id !== null ? $this->rooms->getById((string) $case->operating_room_id) : null;

        if ($room !== null && $room->status === OperatingRoom::STATUS_RESERVED) {
            $room->transitionStatus(OperatingRoom::STATUS_AVAILABLE);
        }

        $case->markNoShow();

        $this->cases->save($case);

        if ($room !== null) {
            $this->rooms->save($room);
        }
    }
}
