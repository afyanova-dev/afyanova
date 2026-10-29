<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Service;

use App\Domains\Laboratory\Domain\Exception\LabResultNotReleasable;
use App\Domains\Laboratory\Domain\Model\LabResult;

/**
 * The result-release safety gate (14-laboratory-domain.md §158, §229;
 * ADR-159 D-E). Pure domain logic — a released result must satisfy every
 * pre-condition (§229: not wrong-patient / wrong-order / invalid-specimen /
 * unverified / blocked).
 *
 * Lab.3a checks: status VERIFIED, and `release_hold_reason` is clear.
 * `release_hold_reason` is the generic block hook — Lab.5 (QC) sets it to
 * `QC_FAILED` for results affected by a failed QC run and clears it on review
 * (§85, §159), making the QC gate a purely additive extension of this service.
 * Patient / order / specimen consistency is enforced at result ENTRY
 * (`RecordResultHandler`), so a VERIFIED result is already correctly bound.
 */
final class LabResultReleaseGate
{
    public function assertReleasable(LabResult $result): void
    {
        if ((string) $result->status !== LabResult::STATUS_VERIFIED) {
            throw LabResultNotReleasable::notVerified((string) $result->id, (string) $result->status);
        }

        if ($result->release_hold_reason !== null && trim((string) $result->release_hold_reason) !== '') {
            throw LabResultNotReleasable::onHold((string) $result->id, (string) $result->release_hold_reason);
        }
    }
}
