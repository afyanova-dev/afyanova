<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RecordFollowUpOutcome` marked a follow-up KEPT without a
 * `resultingEncounterId` (26-care-coordination-domain.md Invariant 7.9;
 * ADR-154 D-C). Maps to HTTP 409.
 */
final class FollowUpOutcomeIncomplete extends DomainException
{
    public static function keptNeedsEncounter(): self
    {
        return new self('A follow-up marked KEPT must reference a resulting encounter (Invariant 7.9).');
    }

    public function errorCode(): string
    {
        return 'follow_up_outcome_incomplete';
    }
}
