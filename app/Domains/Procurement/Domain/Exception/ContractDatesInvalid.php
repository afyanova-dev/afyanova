<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateContract` was given an `effective_date` that is not before the
 * `expiry_date` (19-procurement-domain.md §40; ADR-176 D-B). Maps to HTTP 422.
 */
final class ContractDatesInvalid extends DomainException
{
    public static function effectiveNotBeforeExpiry(string $effective, string $expiry): self
    {
        return new self(sprintf('Contract effective_date [%s] must be before expiry_date [%s].', $effective, $expiry));
    }

    public function errorCode(): string
    {
        return 'contract_dates_invalid';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
