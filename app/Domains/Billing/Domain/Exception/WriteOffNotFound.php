<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `GetWriteOff` named a write-off absent from the current tenant scope
 * (ADR-191 D7-A). Maps to HTTP 404.
 */
final class WriteOffNotFound extends DomainException
{
    public static function id(string $writeOffId): self
    {
        return new self(sprintf('Write-off [%s] not found.', $writeOffId));
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
