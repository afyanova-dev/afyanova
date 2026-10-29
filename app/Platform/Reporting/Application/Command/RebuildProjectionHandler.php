<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Command;

use App\Platform\Reporting\Domain\Model\ProjectionCheckpoint;
use App\Platform\Reporting\Domain\ValueObject\ProjectionStreamStatus;
use App\Platform\Reporting\Infrastructure\Projection\BedOccupancyProjectionBuilder;
use App\Platform\Reporting\Infrastructure\Projection\EncounterProjectionBuilder;
use App\Platform\Reporting\Infrastructure\Projection\LabTatProjectionBuilder;
use App\Platform\Reporting\Infrastructure\Projection\PharmacyProjectionBuilder;
use App\Platform\Reporting\Infrastructure\Projection\RevenueProjectionBuilder;
use Carbon\CarbonImmutable;

final class RebuildProjectionHandler
{
    public function __construct(
        private readonly EncounterProjectionBuilder $encounterBuilder,
        private readonly RevenueProjectionBuilder $revenueBuilder,
        private readonly BedOccupancyProjectionBuilder $bedOccupancyBuilder,
        private readonly LabTatProjectionBuilder $labTatBuilder,
        private readonly ?PharmacyProjectionBuilder $pharmacyBuilder = null,
    ) {}

    public function __invoke(RebuildProjectionCommand $command): ProjectionCheckpoint
    {
        /** @var ProjectionCheckpoint $checkpoint */
        $checkpoint = ProjectionCheckpoint::firstOrCreate(
            [
                'tenant_id' => $command->tenantId,
                'stream_name' => $command->streamName,
            ],
            [
                'checkpoint_offset' => 0,
                'status' => ProjectionStreamStatus::IDLE,
                'reference_config_version' => $command->referenceConfigVersion,
            ]
        );

        $checkpoint->status = ProjectionStreamStatus::REBUILDING;
        $checkpoint->save();

        $processed = match ($command->streamName) {
            'encounters' => $this->encounterBuilder->rebuild($command->tenantId, $command->facilityId),
            'revenue' => $this->revenueBuilder->rebuild($command->tenantId, $command->facilityId),
            'bed_occupancy' => $this->bedOccupancyBuilder->rebuild($command->tenantId, $command->facilityId),
            'lab_tat' => $this->labTatBuilder->rebuild($command->tenantId, $command->facilityId),
            'pharmacy' => ($this->pharmacyBuilder ?? app(PharmacyProjectionBuilder::class))->rebuild($command->tenantId, $command->facilityId),
            default => 0,
        };

        $now = CarbonImmutable::now();
        $checkpoint->status = ProjectionStreamStatus::LIVE;
        $checkpoint->checkpoint_offset = $checkpoint->checkpoint_offset + $processed;
        $checkpoint->last_rebuilt_at = $now;
        $checkpoint->save();

        return $checkpoint;
    }
}
