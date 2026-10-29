<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A query named a payer plan that does not exist in the current tenant scope
 * (ADR-198 D-H / D-I). Maps to HTTP 404.
 */
final class PayerPlanNotFound extends DomainException
{
    public static function id(string $planId): self
    {
        return new self(sprintf('Payer plan [%s] not found.', $planId));
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
