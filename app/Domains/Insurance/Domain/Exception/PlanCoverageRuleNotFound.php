<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A query or command named a coverage rule that does not exist in the current
 * tenant scope (ADR-200 D-B / D-D). Maps to HTTP 404.
 */
final class PlanCoverageRuleNotFound extends DomainException
{
    public static function id(string $ruleId): self
    {
        return new self(sprintf('Plan coverage rule [%s] not found.', $ruleId));
    }

    public function errorCode(): string
    {
        return 'not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
