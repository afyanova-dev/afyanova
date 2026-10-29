<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `SetPlanCoverageRule`'s `effectiveFrom` is not strictly after the currently
 * open rule's `effective_from` (ADR-200 D-B — the `SetTariff` non-overlap
 * guard). Maps to HTTP 409.
 */
final class CoverageRuleEffectiveDateNotAfterCurrent extends DomainException
{
    public static function dates(string $requested, string $current): self
    {
        return new self(sprintf(
            'The new coverage rule effective date [%s] must be strictly after the current open rule\'s [%s].',
            $requested,
            $current,
        ));
    }

    public function errorCode(): string
    {
        return 'coverage_rule_effective_date_not_after_current';
    }
}
