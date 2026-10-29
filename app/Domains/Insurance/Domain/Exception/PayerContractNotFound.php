<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A payer contract was not found in the current tenant scope (ADR-203).
 * Maps to HTTP 404.
 */
final class PayerContractNotFound extends DomainException
{
    public static function id(string $contractId): self
    {
        return new self(sprintf('Payer contract [%s] not found.', $contractId));
    }

    public function errorCode(): string
    {
        return 'payer_contract_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
