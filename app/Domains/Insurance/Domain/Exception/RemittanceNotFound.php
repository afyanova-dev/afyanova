<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A remittance was not found in the current tenant scope (ADR-205).
 * Maps to HTTP 404.
 */
final class RemittanceNotFound extends DomainException
{
    public static function id(string $remittanceId): self
    {
        return new self(sprintf('Remittance [%s] not found.', $remittanceId));
    }

    public function errorCode(): string
    {
        return 'remittance_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
