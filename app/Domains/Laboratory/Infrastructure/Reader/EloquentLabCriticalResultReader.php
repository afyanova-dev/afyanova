<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Reader;

use App\Domains\Laboratory\Contracts\Dto\LabCriticalResultSummary;
use App\Domains\Laboratory\Domain\LabCriticalResultReader;
use App\Domains\Laboratory\Domain\Model\LabCriticalResult;
use App\Shared\Domain\NotFound;

final class EloquentLabCriticalResultReader implements LabCriticalResultReader
{
    public function summary(string $criticalResultId): LabCriticalResultSummary
    {
        $critical = LabCriticalResult::query()->whereKey($criticalResultId)->first()
            ?? throw NotFound::aggregate(LabCriticalResult::class, $criticalResultId);

        return $this->toSummary($critical);
    }

    /**
     * @return list<LabCriticalResultSummary>
     */
    public function pendingForFacility(string $facilityId): array
    {
        $rows = LabCriticalResult::query()
            ->where('facility_id', $facilityId)
            ->where('status', LabCriticalResult::STATUS_INITIATED)
            ->orderBy('identified_at')
            ->get();

        return array_values($rows->map(fn (LabCriticalResult $c): LabCriticalResultSummary => $this->toSummary($c))->all());
    }

    private function toSummary(LabCriticalResult $critical): LabCriticalResultSummary
    {
        return new LabCriticalResultSummary(
            criticalResultId: (string) $critical->id,
            resultId: (string) $critical->result_id,
            orderId: (string) $critical->order_id,
            patientId: (string) $critical->patient_id,
            facilityId: (string) $critical->facility_id,
            testCode: (string) $critical->test_code,
            parameterCode: (string) $critical->parameter_code,
            severity: (string) $critical->severity,
            clinicalContext: $critical->clinical_context,
            status: (string) $critical->status,
            identifiedByUserId: (string) $critical->identified_by_user_id,
            identifiedAt: $critical->identified_at?->toIso8601String(),
            acknowledgedByUserId: $critical->acknowledged_by_user_id,
            acknowledgedAt: $critical->acknowledged_at?->toIso8601String(),
            acknowledgementNote: $critical->acknowledgement_note,
        );
    }
}
