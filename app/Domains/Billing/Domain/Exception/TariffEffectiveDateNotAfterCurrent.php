<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `SetTariff` supplied an `effective_from` that is not strictly after the
 * current open tariff's `effective_from` (20-billing-and-payments-domain.md §19
 * / §20; ADR-188 D-B / F2 — this is what keeps the rows a non-overlapping
 * partition of time). Backdated correction is a future increment
 * (`CorrectTariff`). Maps to HTTP 409.
 */
final class TariffEffectiveDateNotAfterCurrent extends DomainException
{
    public static function dates(string $requested, string $currentOpen): self
    {
        return new self(sprintf(
            'SetTariff effective_from [%s] must be strictly after the current open tariff effective_from [%s].',
            $requested,
            $currentOpen,
        ));
    }

    public function errorCode(): string
    {
        return 'tariff_effective_date_not_after_current';
    }
}
