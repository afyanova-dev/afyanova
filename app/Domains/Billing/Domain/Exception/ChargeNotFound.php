<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `GetCharge` named a charge that does not exist in the current tenant scope
 * (ADR-189 D-G). Maps to HTTP 404.
 */
final class ChargeNotFound extends DomainException
{
    public static function id(string $chargeId): self
    {
        return new self(sprintf('Charge [%s] not found.', $chargeId));
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
