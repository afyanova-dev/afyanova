<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Infrastructure\Reader;

use App\Domains\BedWard\Contracts\Dto\BedSummary;
use App\Domains\BedWard\Domain\BedReader;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Model\Ward;
use App\Shared\Domain\NotFound;

final class EloquentBedReader implements BedReader
{
    public function summary(string $bedId): BedSummary
    {
        $bed = Bed::query()->whereKey($bedId)->first()
            ?? throw NotFound::aggregate(Bed::class, $bedId);

        $features = $bed->features;

        return new BedSummary(
            bedId: (string) $bed->id,
            facilityId: (string) $bed->facility_id,
            wardId: (string) $bed->ward_id,
            roomId: $bed->room_id !== null ? (string) $bed->room_id : null,
            label: (string) $bed->label,
            bedClass: (string) $bed->bed_class,
            features: $features,
            status: (string) $bed->status,
            createdAt: $bed->created_at?->toISOString(),
            updatedAt: $bed->updated_at?->toISOString(),
        );
    }

    /**
     * @param  list<string>  $features
     * @return list<BedSummary>
     */
    public function availableBeds(
        string $facilityId,
        ?string $wardType = null,
        ?string $bedClass = null,
        array $features = [],
    ): array {
        $query = Bed::query()
            ->where('facility_id', $facilityId)
            ->where('status', Bed::STATUS_AVAILABLE);

        if ($bedClass !== null) {
            $query->where('bed_class', $bedClass);
        }

        if ($wardType !== null) {
            $wardIds = Ward::query()
                ->where('facility_id', $facilityId)
                ->where('ward_type', $wardType)
                ->where('status', Ward::STATUS_ACTIVE)
                ->pluck('id');
            $query->whereIn('ward_id', $wardIds);
        }

        $beds = $query->get();

        $summaries = [];
        foreach ($beds as $bed) {
            $bedFeatures = $bed->features;

            // If required features are specified, check that bed features contains all required
            if ($features !== []) {
                $hasAll = true;
                foreach ($features as $requiredFeature) {
                    if (! in_array($requiredFeature, (array) $bedFeatures, true)) {
                        $hasAll = false;
                        break;
                    }
                }
                if (! $hasAll) {
                    continue;
                }
            }

            $summaries[] = new BedSummary(
                bedId: (string) $bed->id,
                facilityId: (string) $bed->facility_id,
                wardId: (string) $bed->ward_id,
                roomId: $bed->room_id !== null ? (string) $bed->room_id : null,
                label: (string) $bed->label,
                bedClass: (string) $bed->bed_class,
                features: $bedFeatures,
                status: (string) $bed->status,
                createdAt: $bed->created_at?->toISOString(),
                updatedAt: $bed->updated_at?->toISOString(),
            );
        }

        return $summaries;
    }
}
