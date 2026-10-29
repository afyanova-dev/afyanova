<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Reader;

use App\Domains\Laboratory\Contracts\Dto\LabResultAmendmentSummary;
use App\Domains\Laboratory\Contracts\Dto\LabResultSummary;
use App\Domains\Laboratory\Domain\LabResultReader;
use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Domains\Laboratory\Domain\Model\LabResultAmendment;
use App\Shared\Domain\NotFound;

final class EloquentLabResultReader implements LabResultReader
{
    /**
     * @return list<LabResultAmendmentSummary>
     */
    public function amendments(string $resultId): array
    {
        $rows = LabResultAmendment::query()
            ->where('result_id', $resultId)
            ->orderBy('sequence')
            ->get();

        return array_values($rows->map(fn (LabResultAmendment $a): LabResultAmendmentSummary => new LabResultAmendmentSummary(
            amendmentId: (string) $a->id,
            resultId: (string) $a->result_id,
            sequence: (int) $a->sequence,
            amendmentType: (string) $a->amendment_type,
            previousStatus: (string) $a->previous_status,
            newStatus: (string) $a->new_status,
            previousValueType: $a->previous_value_type,
            previousValueNumeric: $a->previous_value_numeric !== null ? (string) $a->previous_value_numeric : null,
            previousValueCode: $a->previous_value_code,
            previousValueText: $a->previous_value_text,
            newValueType: $a->new_value_type,
            newValueNumeric: $a->new_value_numeric !== null ? (string) $a->new_value_numeric : null,
            newValueCode: $a->new_value_code,
            newValueText: $a->new_value_text,
            reason: (string) $a->reason,
            replacementResultId: $a->replacement_result_id,
            amendedByUserId: (string) $a->amended_by_user_id,
            amendedAt: $a->amended_at?->toIso8601String(),
        ))->all());
    }

    /**
     * @return list<string>
     */
    public function verifiedUnreleasedIdsForTest(string $facilityId, ?string $testDefinitionId): array
    {
        $query = LabResult::query()
            ->where('facility_id', $facilityId)
            ->where('status', LabResult::STATUS_VERIFIED)
            ->whereNull('release_hold_reason');

        if ($testDefinitionId !== null) {
            $query->where('test_definition_id', $testDefinitionId);
        }

        return array_values(array_map('strval', $query->pluck('id')->all()));
    }

    /**
     * @return list<string>
     */
    public function heldIdsForTest(string $facilityId, ?string $testDefinitionId, string $reason): array
    {
        $query = LabResult::query()
            ->where('facility_id', $facilityId)
            ->where('release_hold_reason', $reason);

        if ($testDefinitionId !== null) {
            $query->where('test_definition_id', $testDefinitionId);
        }

        return array_values(array_map('strval', $query->pluck('id')->all()));
    }

    public function summary(string $resultId): LabResultSummary
    {
        $result = LabResult::query()->whereKey($resultId)->first()
            ?? throw NotFound::aggregate(LabResult::class, $resultId);

        return $this->toSummary($result);
    }

    /**
     * @return list<LabResultSummary>
     */
    public function forOrder(string $orderId): array
    {
        $results = LabResult::query()
            ->where('order_id', $orderId)
            ->orderBy('test_code')
            ->orderBy('parameter_code')
            ->orderBy('id')
            ->get();

        return array_values($results->map(fn (LabResult $r): LabResultSummary => $this->toSummary($r))->all());
    }

    private function toSummary(LabResult $result): LabResultSummary
    {
        return new LabResultSummary(
            resultId: (string) $result->id,
            orderId: (string) $result->order_id,
            orderItemId: (string) $result->order_item_id,
            specimenId: (string) $result->specimen_id,
            patientId: (string) $result->patient_id,
            facilityId: (string) $result->facility_id,
            testCode: (string) $result->test_code,
            parameterCode: (string) $result->parameter_code,
            parameterName: (string) $result->parameter_name,
            valueType: (string) $result->value_type,
            valueNumeric: $result->value_numeric !== null ? (string) $result->value_numeric : null,
            valueCode: $result->value_code,
            valueText: $result->value_text,
            unit: $result->unit,
            referenceLow: $result->reference_low !== null ? (string) $result->reference_low : null,
            referenceHigh: $result->reference_high !== null ? (string) $result->reference_high : null,
            referenceText: $result->reference_text,
            referenceRangeSource: $result->reference_range_source,
            abnormalFlag: $result->abnormal_flag,
            resultSource: (string) $result->result_source,
            method: $result->method,
            status: (string) $result->status,
            releaseHoldReason: $result->release_hold_reason,
            enteredAt: $result->entered_at?->toIso8601String(),
            verifiedAt: $result->verified_at?->toIso8601String(),
            releasedAt: $result->released_at?->toIso8601String(),
        );
    }
}
