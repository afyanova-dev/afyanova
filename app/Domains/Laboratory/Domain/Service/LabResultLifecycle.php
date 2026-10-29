<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Service;

use App\Domains\Laboratory\Domain\Exception\IllegalLabResultTransition;
use App\Domains\Laboratory\Domain\Model\LabResult;

/**
 * Laboratory-result lifecycle rules (14-laboratory-domain.md §57, §157;
 * ADR-159 D-E — the `LabOrderLifecycle` precedent). Pure domain logic.
 *
 * Lab.3a: ENTERED -> REVIEWED -> VERIFIED -> RELEASED. Entry and verification
 * are distinct STEPS (Invariant 13) — a same-user maker-checker block is a
 * governance refinement, not enforced here (the T.1 safety-checklist interim
 * floor precedent).
 *
 * ADR-160 (Lab.3b): a CORRECTION (`AmendResult`) applies to a finalized
 * result ({VERIFIED, RELEASED, AMENDED} -> AMENDED); a CANCELLATION
 * (`CancelResult`) invalidates a result from any non-terminal state
 * (-> CANCELLED). Both append a `lab_result_amendments` row (§62 no silent
 * editing).
 */
final class LabResultLifecycle
{
    public function assertCanAmend(string $status): void
    {
        if (! in_array($status, [LabResult::STATUS_VERIFIED, LabResult::STATUS_RELEASED, LabResult::STATUS_AMENDED], true)) {
            throw IllegalLabResultTransition::fromStatus('AmendResult', $status);
        }
    }

    public function assertCanCancel(string $status): void
    {
        if ($status === LabResult::STATUS_CANCELLED) {
            throw IllegalLabResultTransition::fromStatus('CancelResult', $status);
        }
    }

    public function assertCanReview(string $status): void
    {
        if ($status !== LabResult::STATUS_ENTERED) {
            throw IllegalLabResultTransition::fromStatus('ReviewResult', $status);
        }
    }

    public function assertCanVerify(string $status): void
    {
        if (! in_array($status, [LabResult::STATUS_ENTERED, LabResult::STATUS_REVIEWED], true)) {
            throw IllegalLabResultTransition::fromStatus('VerifyResult', $status);
        }
    }

    public function assertCanRelease(string $status): void
    {
        if ($status !== LabResult::STATUS_VERIFIED) {
            throw IllegalLabResultTransition::fromStatus('ReleaseResult', $status);
        }
    }
}
