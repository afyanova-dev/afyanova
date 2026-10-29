<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * GetCashSession named a session absent from the current tenant scope (ADR-191 C6). Maps to HTTP 404.
 */
final class CashSessionNotFound extends DomainException
{
    public static function id(string $cashSessionId): self
    {
        return new self(sprintf(
            'Cash session [%s] not found.',
            $cashSessionId,
        ));
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
