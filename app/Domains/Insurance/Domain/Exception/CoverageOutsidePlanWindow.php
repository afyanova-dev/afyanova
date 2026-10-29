<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RegisterMemberCoverage`'s effective window falls outside the plan's own
 * effective window (ADR-198 D-H / ADR-199 D-E). Maps to HTTP 409.
 */
final class CoverageOutsidePlanWindow extends DomainException
{
    public static function id(string $planId): self
    {
        return new self(sprintf(
            'The coverage effective window falls outside plan [%s]\'s effective window.',
            $planId,
        ));
    }

    public function errorCode(): string
    {
        return 'coverage_outside_plan_window';
    }
}
