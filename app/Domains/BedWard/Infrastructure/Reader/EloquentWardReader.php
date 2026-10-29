<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Infrastructure\Reader;

use App\Domains\BedWard\Contracts\Dto\FacilityOccupancySummary;
use App\Domains\BedWard\Contracts\Dto\WardSummary;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Model\Ward;
use App\Domains\BedWard\Domain\WardReader;
use App\Shared\Domain\NotFound;

final class EloquentWardReader implements WardReader
{
    public function summary(string $wardId): WardSummary
    {
        $ward = Ward::query()->whereKey($wardId)->first()
            ?? throw NotFound::aggregate(Ward::class, $wardId);

        return new WardSummary(
            wardId: (string) $ward->id,
            facilityId: (string) $ward->facility_id,
            code: (string) $ward->code,
            name: (string) $ward->name,
            wardType: (string) $ward->ward_type,
            capabilityLink: $ward->capability_link !== null ? (string) $ward->capability_link : null,
            capacity: (int) $ward->capacity,
            status: (string) $ward->status,
            createdAt: $ward->created_at?->toISOString(),
            updatedAt: $ward->updated_at?->toISOString(),
        );
    }

    public function census(string $wardId): array
    {
        $ward = Ward::query()->whereKey($wardId)->first()
            ?? throw NotFound::aggregate(Ward::class, $wardId);

        $counts = [
            'AVAILABLE' => 0,
            'OCCUPIED' => 0,
            'CLEANING' => 0,
            'BLOCKED' => 0,
            'MAINTENANCE' => 0,
            'RESERVED' => 0,
            'TOTAL' => 0,
        ];

        $beds = Bed::query()
            ->where('ward_id', $ward->id)
            ->where('status', '!=', Bed::STATUS_DECOMMISSIONED)
            ->get(['status']);

        foreach ($beds as $bed) {
            $status = (string) $bed->status;
            if (isset($counts[$status])) {
                $counts[$status]++;
            } else {
                $counts[$status] = 1;
            }
            $counts['TOTAL']++;
        }

        return $counts;
    }

    public function facilityOccupancy(string $facilityId): FacilityOccupancySummary
    {
        $beds = Bed::query()
            ->where('facility_id', $facilityId)
            ->where('status', '!=', Bed::STATUS_DECOMMISSIONED)
            ->get(['ward_id', 'bed_class', 'status']);

        $total = 0;
        $occupied = 0;
        $available = 0;
        $cleaning = 0;
        $blocked = 0;

        /** @var array<string, array{total: int, occupied: int, available: int, blocked: int, cleaning: int, occupancy_rate: float}> $byWard */
        $byWard = [];
        /** @var array<string, array{total: int, occupied: int, available: int, blocked: int, cleaning: int, occupancy_rate: float}> $byBedClass */
        $byBedClass = [];

        foreach ($beds as $bed) {
            $status = (string) $bed->status;
            $wardId = (string) $bed->ward_id;
            $class = (string) $bed->bed_class;

            $total++;
            if ($status === Bed::STATUS_OCCUPIED) {
                $occupied++;
            } elseif ($status === Bed::STATUS_AVAILABLE) {
                $available++;
            } elseif ($status === Bed::STATUS_CLEANING) {
                $cleaning++;
            } elseif ($status === Bed::STATUS_BLOCKED || $status === Bed::STATUS_MAINTENANCE) {
                $blocked++;
            }

            if (! isset($byWard[$wardId])) {
                $byWard[$wardId] = ['total' => 0, 'occupied' => 0, 'available' => 0, 'blocked' => 0, 'cleaning' => 0, 'occupancy_rate' => 0.0];
            }
            $byWard[$wardId]['total']++;
            if ($status === Bed::STATUS_OCCUPIED) {
                $byWard[$wardId]['occupied']++;
            } elseif ($status === Bed::STATUS_AVAILABLE) {
                $byWard[$wardId]['available']++;
            } elseif ($status === Bed::STATUS_CLEANING) {
                $byWard[$wardId]['cleaning']++;
            } elseif ($status === Bed::STATUS_BLOCKED || $status === Bed::STATUS_MAINTENANCE) {
                $byWard[$wardId]['blocked']++;
            }

            if (! isset($byBedClass[$class])) {
                $byBedClass[$class] = ['total' => 0, 'occupied' => 0, 'available' => 0, 'blocked' => 0, 'cleaning' => 0, 'occupancy_rate' => 0.0];
            }
            $byBedClass[$class]['total']++;
            if ($status === Bed::STATUS_OCCUPIED) {
                $byBedClass[$class]['occupied']++;
            } elseif ($status === Bed::STATUS_AVAILABLE) {
                $byBedClass[$class]['available']++;
            } elseif ($status === Bed::STATUS_CLEANING) {
                $byBedClass[$class]['cleaning']++;
            } elseif ($status === Bed::STATUS_BLOCKED || $status === Bed::STATUS_MAINTENANCE) {
                $byBedClass[$class]['blocked']++;
            }
        }

        foreach ($byWard as $wId => $stats) {
            $byWard[$wId]['occupancy_rate'] = $stats['total'] > 0 ? round(($stats['occupied'] / $stats['total']) * 100, 2) : 0.0;
        }

        foreach ($byBedClass as $bClass => $stats) {
            $byBedClass[$bClass]['occupancy_rate'] = $stats['total'] > 0 ? round(($stats['occupied'] / $stats['total']) * 100, 2) : 0.0;
        }

        $overallRate = $total > 0 ? round(($occupied / $total) * 100, 2) : 0.0;

        return new FacilityOccupancySummary(
            facilityId: $facilityId,
            totalBeds: $total,
            occupiedBeds: $occupied,
            availableBeds: $available,
            cleaningBeds: $cleaning,
            blockedBeds: $blocked,
            occupancyRate: $overallRate,
            byWard: $byWard,
            byBedClass: $byBedClass,
        );
    }
}
