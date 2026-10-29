<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `SetPlanCoverageRule` carried terms inconsistent with its `coverage_status`
 * (ADR-200 D-B): NOT_COVERED with a percent / co-pay / fixed benefit, or
 * `coverage_percent` outside 0..100. Maps to HTTP 409.
 */
final class InvalidCoverageRule extends DomainException
{
    public static function notCoveredWithTerms(): self
    {
        return new self('A NOT_COVERED rule must carry no coverage percent, co-pay or fixed benefit.');
    }

    public static function percentOutOfRange(int $percent): self
    {
        return new self(sprintf('coverage_percent [%d] must be between 0 and 100.', $percent));
    }

    public function errorCode(): string
    {
        return 'invalid_coverage_rule';
    }
}
