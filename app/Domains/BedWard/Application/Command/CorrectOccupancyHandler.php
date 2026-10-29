<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\BedOccupancyRepository;
use App\Shared\Audit\AuditLogger;

final class CorrectOccupancyHandler
{
    public function __construct(
        private readonly BedOccupancyRepository $occupancies,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(CorrectOccupancy $command): CorrectOccupancyResult
    {
        $occupancy = $this->occupancies->getById($command->occupancyId);

        $diff = $occupancy->correct($command->changes);

        if ($diff['changedFields'] === []) {
            return new CorrectOccupancyResult((string) $occupancy->id, []);
        }

        $this->occupancies->save($occupancy);

        $this->audit->record(
            action: 'bed_ward.occupancy_corrected',
            actorId: $command->actorId,
            targetType: 'bed_occupancy',
            targetId: (string) $occupancy->id,
            reason: $command->reason,
            metadata: [
                'changed_fields' => $diff['changedFields'],
                'previous' => $diff['previous'],
                'new' => $diff['new'],
            ],
        );

        return new CorrectOccupancyResult((string) $occupancy->id, $diff['changedFields']);
    }
}
