<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A concurrent `SetPayerTariff` for the same (contract, service_category)
 * scope raced the partial unique index (ADR-203). Retry. Maps to HTTP 409.
 */
final class PayerTariffChangeConflict extends DomainException
{
    public static function forScope(string $contractId, string $serviceCategory): self
    {
        return new self(sprintf(
            'A concurrent tariff change for contract [%s] / category [%s] conflicted — retry.',
            $contractId,
            $serviceCategory,
        ));
    }

    public function errorCode(): string
    {
        return 'payer_tariff_change_conflict';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
