<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `EndPlanCoverageRule` targeted a scope with no open rule, or an
 * `effectiveTo` not strictly after the open rule's `effective_from` (ADR-200
 * D-B — the `EndTariff` guard). Maps to HTTP 409.
 */
final class IllegalCoverageRuleTransition extends DomainException
{
    public static function noOpenRow(string $planId, string $serviceCategory): self
    {
        return new self(sprintf(
            'No open coverage rule for plan [%s] / category [%s] to end.',
            $planId,
            $serviceCategory,
        ));
    }

    public static function endDateNotAfterStart(string $endDate, string $startDate): self
    {
        return new self(sprintf(
            'EndPlanCoverageRule effective date [%s] must be strictly after the rule\'s start [%s].',
            $endDate,
            $startDate,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_coverage_rule_transition';
    }
}
