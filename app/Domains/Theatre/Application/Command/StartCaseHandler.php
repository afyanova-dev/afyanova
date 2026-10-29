<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Domains\Theatre\Domain\OperatingRoomRepository;
use App\Domains\Theatre\Domain\Service\SurgicalCaseLifecycle;
use App\Domains\Theatre\Domain\SurgicalCaseRepository;

final class StartCaseHandler
{
    public function __construct(
        private readonly SurgicalCaseRepository $cases,
        private readonly OperatingRoomRepository $rooms,
        private readonly SurgicalCaseLifecycle $lifecycle,
    ) {}

    public function __invoke(StartCase $command): void
    {
        $case = $this->cases->getById($command->caseId);

        $this->lifecycle->assertCanStart((string) $case->status, $case->safety_checklist);

        $room = $this->rooms->getById((string) $case->operating_room_id);
        $this->lifecycle->assertRoomCanTransition((string) $room->status, OperatingRoom::STATUS_IN_USE);

        $case->start();
        $room->transitionStatus(OperatingRoom::STATUS_IN_USE);

        $this->cases->save($case);
        $this->rooms->save($room);
    }
}
