<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A payer contract was supplied with an invalid effective window (ADR-203).
 * E.g., effective_to before effective_from. Maps to HTTP 422.
 */
final class InvalidPayerContractDates extends DomainException
{
    public static function window(string $effectiveFrom, string $effectiveTo): self
    {
        return new self(sprintf(
            'Contract effective_from [%s] must be on or before effective_to [%s].',
            $effectiveFrom,
            $effectiveTo,
        ));
    }

    public function errorCode(): string
    {
        return 'invalid_payer_contract_dates';
    }
}
