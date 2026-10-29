<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Reader;

use App\Domains\Laboratory\Contracts\Dto\LabQcRunSummary;
use App\Domains\Laboratory\Domain\LabQcRunReader;
use App\Domains\Laboratory\Domain\Model\LabQcRun;
use App\Shared\Domain\NotFound;

final class EloquentLabQcRunReader implements LabQcRunReader
{
    public function summary(string $qcRunId): LabQcRunSummary
    {
        $run = LabQcRun::query()->whereKey($qcRunId)->first()
            ?? throw NotFound::aggregate(LabQcRun::class, $qcRunId);

        return $this->toSummary($run);
    }

    /**
     * @return list<LabQcRunSummary>
     */
    public function recentForFacility(string $facilityId, int $limit = 50): array
    {
        $runs = LabQcRun::query()
            ->where('facility_id', $facilityId)
            ->orderByDesc('performed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return array_values($runs->map(fn (LabQcRun $r): LabQcRunSummary => $this->toSummary($r))->all());
    }

    private function toSummary(LabQcRun $run): LabQcRunSummary
    {
        return new LabQcRunSummary(
            qcRunId: (string) $run->id,
            facilityId: (string) $run->facility_id,
            analyzerRef: (string) $run->analyzer_ref,
            testDefinitionId: $run->test_definition_id,
            testCode: $run->test_code,
            controlMaterial: (string) $run->control_material,
            controlLevel: $run->control_level,
            expectedLow: $run->expected_low !== null ? (string) $run->expected_low : null,
            expectedHigh: $run->expected_high !== null ? (string) $run->expected_high : null,
            observedValue: (string) $run->observed_value,
            unit: $run->unit,
            status: (string) $run->status,
            reviewedByUserId: $run->reviewed_by_user_id,
            reviewedAt: $run->reviewed_at?->toIso8601String(),
            reviewNote: $run->review_note,
            performedByUserId: (string) $run->performed_by_user_id,
            performedAt: $run->performed_at?->toIso8601String(),
        );
    }
}
