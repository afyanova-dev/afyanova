<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Service;

use App\Domains\Laboratory\Domain\Exception\IllegalQcRunTransition;
use App\Domains\Laboratory\Domain\Model\LabQcRun;

/**
 * QC-run rules (14-laboratory-domain.md §83–§85; ADR-163 D-E). Pure domain
 * logic.
 */
final class LabQcRunLifecycle
{
    /**
     * Classify an observed value against the expected range (§83). A missing
     * bound on that side is not a failure.
     */
    public function classify(float $observed, ?float $expectedLow, ?float $expectedHigh): string
    {
        if ($expectedHigh !== null && $observed > $expectedHigh) {
            return LabQcRun::STATUS_FAIL;
        }

        if ($expectedLow !== null && $observed < $expectedLow) {
            return LabQcRun::STATUS_FAIL;
        }

        return LabQcRun::STATUS_PASS;
    }

    public function assertCanReview(LabQcRun $run): void
    {
        if ($run->reviewed_at !== null) {
            throw IllegalQcRunTransition::alreadyReviewed((string) $run->id);
        }

        if ((string) $run->status === LabQcRun::STATUS_PASS) {
            throw IllegalQcRunTransition::passingRunNeedsNoReview((string) $run->id);
        }
    }
}
