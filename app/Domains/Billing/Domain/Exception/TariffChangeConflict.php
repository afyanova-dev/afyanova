<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A concurrent `SetTariff` opened a tariff row for the same (service, scope)
 * between this handler's check and its insert — the partial unique index
 * `bill_tariffs_one_open_per_scope` rejected the second row (ADR-188 D-C / F1).
 * The caller should retry against the new state. Maps to HTTP 409.
 */
final class TariffChangeConflict extends DomainException
{
    public static function forScope(string $billingServiceId): self
    {
        return new self(sprintf(
            'A concurrent SetTariff opened a tariff row for service [%s] in this scope; retry.',
            $billingServiceId,
        ));
    }

    public function errorCode(): string
    {
        return 'tariff_change_conflict';
    }
}
