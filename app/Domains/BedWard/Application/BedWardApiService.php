<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application;

use App\Domains\BedWard\Application\Command\AssignBed;
use App\Domains\BedWard\Contracts\BedWardApi;
use App\Domains\BedWard\Contracts\Dto\BedOccupancyRef;
use App\Domains\BedWard\Domain\BedOccupancyReader;
use App\Shared\Bus\CommandBus;

/**
 * BedWardApi port implementation (ADR-147 D-H, ADR-148 D-K, ADR-150 D-H; IC §5.2).
 */
final class BedWardApiService implements BedWardApi
{
    public function __construct(
        private readonly BedOccupancyReader $reader,
        private readonly CommandBus $bus,
    ) {}

    public function activeOccupancyFor(string $encounterId): ?BedOccupancyRef
    {
        return $this->reader->activeForEncounter($encounterId);
    }

    public function assignBed(string $occupancyId, string $encounterId, string $bedId, string $actorId): void
    {
        $this->bus->dispatch(new AssignBed(
            occupancyId: $occupancyId,
            encounterId: $encounterId,
            bedId: $bedId,
            actorId: $actorId,
        ));
    }
}
