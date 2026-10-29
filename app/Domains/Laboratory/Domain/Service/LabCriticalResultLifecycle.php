<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Service;

use App\Domains\Laboratory\Domain\Exception\IllegalCriticalResultTransition;
use App\Domains\Laboratory\Domain\Model\LabCriticalResult;
use App\Domains\Laboratory\Domain\Model\LabResult;

/**
 * Critical-result lifecycle rules (14-laboratory-domain.md §54–§56, §160;
 * ADR-161 D-E). Pure domain logic.
 *
 * A critical result is recorded against a result that has reached a
 * meaningful state (VERIFIED / RELEASED / AMENDED — §160 "Result Verified →
 * Critical Threshold Check"). Acknowledgement: INITIATED -> ACKNOWLEDGED.
 */
final class LabCriticalResultLifecycle
{
    public function assertResultCanBeFlagged(string $resultStatus): void
    {
        if (! in_array($resultStatus, [
            LabResult::STATUS_VERIFIED,
            LabResult::STATUS_RELEASED,
            LabResult::STATUS_AMENDED,
        ], true)) {
            throw IllegalCriticalResultTransition::resultNotFlaggable($resultStatus);
        }
    }

    public function assertCanAcknowledge(string $status): void
    {
        if ($status !== LabCriticalResult::STATUS_INITIATED) {
            throw IllegalCriticalResultTransition::fromStatus('AcknowledgeCriticalResult', $status);
        }
    }
}
