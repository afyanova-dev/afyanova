<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain;

use App\Domains\Theatre\Contracts\Dto\OperatingRoomSummary;
use App\Domains\Theatre\Contracts\Dto\OrBoardEntry;

/**
 * Read-side port for OperatingRoom queries (ADR-150 D-J).
 */
interface OperatingRoomReader
{
    public function summary(string $roomId): OperatingRoomSummary;

    /**
     * @return list<OrBoardEntry>
     */
    public function orBoard(string $facilityId): array;
}
