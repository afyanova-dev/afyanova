<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CorrectPrescribingDecision` was attempted against a decision that has already
 * been superseded by a prior correction (ADR-143 D-J).
 * Maps to HTTP 409 (IC §15).
 */
final class PrescribingDecisionAlreadySuperseded extends DomainException
{
    public static function for(string $decisionId, string $supersededByDecisionId): self
    {
        return new self(sprintf(
            'PrescribingDecision [%s] was already superseded by [%s] — correct the current decision in the chain.',
            $decisionId,
            $supersededByDecisionId,
        ));
    }

    public function errorCode(): string
    {
        return 'prescribing_decision_already_superseded';
    }
}
