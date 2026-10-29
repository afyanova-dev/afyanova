<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A query or command named a billing service that does not exist in the current
 * tenant scope (ADR-188 D-D / F6 — reserved for an unknown id; "configured but
 * not yet priced" is `resolvedScope = NONE`, not this). Maps to HTTP 404.
 */
final class BillingServiceNotFound extends DomainException
{
    public static function id(string $billingServiceId): self
    {
        return new self(sprintf('Billing service [%s] not found.', $billingServiceId));
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
