<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\LabResultRepository;
use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Domains\Laboratory\Domain\Model\LabResultAmendment;
use App\Domains\Laboratory\Domain\Service\LabResultLifecycle;
use Carbon\CarbonImmutable;

final class CancelResultHandler
{
    public function __construct(
        private readonly LabResultRepository $results,
        private readonly LabResultLifecycle $lifecycle,
    ) {}

    public function __invoke(CancelResult $command): void
    {
        $result = $this->results->getByIdForUpdate($command->resultId);

        $this->lifecycle->assertCanCancel((string) $result->status);

        $previousStatus = (string) $result->status;
        $previousValueType = (string) $result->value_type;
        $previousNumeric = $result->value_numeric !== null ? (float) $result->value_numeric : null;
        $previousCode = $result->value_code;
        $previousText = $result->value_text;

        $result->cancelResult($command->reason);

        $sequence = $this->results->nextAmendmentSequence($command->resultId);

        LabResultAmendment::create([
            'id' => $command->amendmentId,
            'tenant_id' => $result->tenant_id,
            'facility_id' => $result->facility_id,
            'result_id' => $result->id,
            'sequence' => $sequence,
            'amendment_type' => LabResultAmendment::TYPE_CANCELLATION,
            'previous_status' => $previousStatus,
            'new_status' => LabResult::STATUS_CANCELLED,
            'previous_value_type' => $previousValueType,
            'previous_value_numeric' => $previousNumeric,
            'previous_value_code' => $previousCode,
            'previous_value_text' => $previousText,
            'new_value_type' => null,
            'new_value_numeric' => null,
            'new_value_code' => null,
            'new_value_text' => null,
            'reason' => trim($command->reason),
            'replacement_result_id' => $command->replacementResultId,
            'amended_by_user_id' => $command->actorId,
            'amended_at' => CarbonImmutable::now(),
        ]);

        $this->results->save($result);
    }
}
