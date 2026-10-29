<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Infrastructure\Reader;

use App\Domains\Theatre\Contracts\Dto\OperatingRoomSummary;
use App\Domains\Theatre\Contracts\Dto\OrBoardEntry;
use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Domains\Theatre\Domain\OperatingRoomReader;
use App\Shared\Domain\NotFound;

final class EloquentOperatingRoomReader implements OperatingRoomReader
{
    public function summary(string $roomId): OperatingRoomSummary
    {
        $room = OperatingRoom::query()->whereKey($roomId)->first()
            ?? throw NotFound::aggregate(OperatingRoom::class, $roomId);

        return new OperatingRoomSummary(
            roomId: (string) $room->id,
            facilityId: (string) $room->facility_id,
            orgRoomId: (string) $room->org_room_id,
            label: (string) $room->label,
            status: (string) $room->status,
            createdAt: $room->created_at?->toISOString(),
            updatedAt: $room->updated_at?->toISOString(),
        );
    }

    /**
     * @return list<OrBoardEntry>
     */
    public function orBoard(string $facilityId): array
    {
        $rooms = OperatingRoom::query()->where('facility_id', $facilityId)->get();

        $activeCasesByRoom = SurgicalCase::query()
            ->where('facility_id', $facilityId)
            ->whereIn('status', SurgicalCase::ACTIVE_STATUSES)
            ->whereNotNull('operating_room_id')
            ->get()
            ->keyBy('operating_room_id');

        $entries = [];
        foreach ($rooms as $room) {
            /** @var SurgicalCase|null $case */
            $case = $activeCasesByRoom->get((string) $room->id);

            $entries[] = new OrBoardEntry(
                roomId: (string) $room->id,
                label: (string) $room->label,
                roomStatus: (string) $room->status,
                currentCaseId: $case !== null ? (string) $case->id : null,
                currentCaseNumber: $case?->case_number,
                currentCaseStatus: $case?->status,
                currentCasePriority: $case?->priority,
            );
        }

        return $entries;
    }
}
