<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A query or command named a payer that does not exist in the current tenant
 * scope (ADR-198 D-G / D-I). Maps to HTTP 404.
 */
final class PayerNotFound extends DomainException
{
    public static function id(string $payerId): self
    {
        return new self(sprintf('Payer [%s] not found.', $payerId));
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
