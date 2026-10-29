<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\LabResultRepository;
use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Domains\Laboratory\Domain\Model\LabResultAmendment;
use App\Domains\Laboratory\Domain\Service\LabResultLifecycle;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `AmendResult` (ADR-160 D-F). Appends the append-only
 * `lab_result_amendments` row (before/after + reason) and flips the result to
 * AMENDED via the aggregate.
 *
 * Loaded `getByIdForUpdate` — a 2nd amendment on an already-AMENDED result
 * re-assigns `status` to the same value; `amendment_count` keeps the aggregate
 * dirty so `save()` still runs the optimistic-lock UPDATE, and the FOR UPDATE
 * lock serializes concurrent amendments (the T.2 lesson).
 */
final class AmendResultHandler
{
    public function __construct(
        private readonly LabResultRepository $results,
        private readonly LabResultLifecycle $lifecycle,
    ) {}

    public function __invoke(AmendResult $command): void
    {
        $result = $this->results->getByIdForUpdate($command->resultId);

        $this->lifecycle->assertCanAmend((string) $result->status);

        $previousStatus = (string) $result->status;
        $previousValueType = (string) $result->value_type;
        $previousNumeric = $result->value_numeric !== null ? (float) $result->value_numeric : null;
        $previousCode = $result->value_code;
        $previousText = $result->value_text;

        $newAbnormalFlag = self::computeFlag($command);

        $result->amend([
            'valueType' => $command->valueType,
            'valueNumeric' => $command->valueNumeric,
            'valueCode' => self::normaliseCode($command->valueCode),
            'valueText' => self::blankToNull($command->valueText),
            'unit' => self::blankToNull($command->unit) ?? $result->unit,
            'abnormalFlag' => $newAbnormalFlag,
        ]);

        $sequence = $this->results->nextAmendmentSequence($command->resultId);

        LabResultAmendment::create([
            'id' => $command->amendmentId,
            'tenant_id' => $result->tenant_id,
            'facility_id' => $result->facility_id,
            'result_id' => $result->id,
            'sequence' => $sequence,
            'amendment_type' => LabResultAmendment::TYPE_CORRECTION,
            'previous_status' => $previousStatus,
            'new_status' => LabResult::STATUS_AMENDED,
            'previous_value_type' => $previousValueType,
            'previous_value_numeric' => $previousNumeric,
            'previous_value_code' => $previousCode,
            'previous_value_text' => $previousText,
            'new_value_type' => $command->valueType,
            'new_value_numeric' => $command->valueNumeric,
            'new_value_code' => self::normaliseCode($command->valueCode),
            'new_value_text' => self::blankToNull($command->valueText),
            'reason' => trim($command->reason),
            'replacement_result_id' => null,
            'amended_by_user_id' => $command->actorId,
            'amended_at' => CarbonImmutable::now(),
        ]);

        $this->results->save($result);
    }

    private static function computeFlag(AmendResult $command): ?string
    {
        if ($command->valueType !== LabResult::VALUE_TYPE_NUMERIC || $command->valueNumeric === null) {
            return null;
        }

        if ($command->referenceHigh !== null && $command->valueNumeric > $command->referenceHigh) {
            return LabResult::FLAG_HIGH;
        }

        if ($command->referenceLow !== null && $command->valueNumeric < $command->referenceLow) {
            return LabResult::FLAG_LOW;
        }

        if ($command->referenceLow !== null || $command->referenceHigh !== null) {
            return LabResult::FLAG_NORMAL;
        }

        return null;
    }

    private static function normaliseCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtoupper(trim($value));

        return $trimmed === '' ? null : $trimmed;
    }

    private static function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
