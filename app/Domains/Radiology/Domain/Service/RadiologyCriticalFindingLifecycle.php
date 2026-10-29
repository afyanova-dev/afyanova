<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Service;

use App\Domains\Radiology\Domain\Exception\IllegalRadiologyCriticalFindingTransition;
use App\Domains\Radiology\Domain\Model\RadiologyCriticalFinding;

/**
 * Radiology critical-finding lifecycle rules (15-radiology-domain.md §101,
 * §167; ADR-169 D-C — the `LabCriticalResultLifecycle` precedent). Pure domain
 * logic.
 *
 * INITIATED -> ACKNOWLEDGED. (The "flaggable only when the report is VERIFIED /
 * RELEASED / AMENDED" rule lives on `RadiologyReportLifecycle::assertCanFlagCriticalFinding`.)
 */
final class RadiologyCriticalFindingLifecycle
{
    public function assertCanAcknowledge(string $status): void
    {
        if ($status !== RadiologyCriticalFinding::STATUS_INITIATED) {
            throw IllegalRadiologyCriticalFindingTransition::fromStatus('AcknowledgeCriticalFinding', $status);
        }
    }
}
