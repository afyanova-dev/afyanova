<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A concurrent `SetPlanCoverageRule` for the same (plan, service_category)
 * scope raced the partial unique index (ADR-200 D-B). Retry. Maps to HTTP 409.
 */
final class CoverageRuleChangeConflict extends DomainException
{
    public static function forScope(string $planId, string $serviceCategory): self
    {
        return new self(sprintf(
            'A concurrent coverage-rule change for plan [%s] / category [%s] conflicted — retry.',
            $planId,
            $serviceCategory,
        ));
    }

    public function errorCode(): string
    {
        return 'coverage_rule_change_conflict';
    }
}
