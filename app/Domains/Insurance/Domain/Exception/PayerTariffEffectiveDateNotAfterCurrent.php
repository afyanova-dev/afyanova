<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `SetPayerTariff`'s `effectiveFrom` is not strictly after the currently
 * open tariff's `effective_from` (ADR-203 — the bill_tariffs non-overlap guard).
 * Maps to HTTP 409.
 */
final class PayerTariffEffectiveDateNotAfterCurrent extends DomainException
{
    public static function dates(string $requested, string $current): self
    {
        return new self(sprintf(
            'The new payer tariff effective date [%s] must be strictly after the current open tariff\'s [%s].',
            $requested,
            $current,
        ));
    }

    public function errorCode(): string
    {
        return 'payer_tariff_effective_date_not_after_current';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
