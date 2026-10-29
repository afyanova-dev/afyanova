<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A tariff command was dispatched against a scope with no open row, or an
 * `EndTariff` `effective_to` was not after the open row's `effective_from`
 * (ADR-188 D-C). Maps to HTTP 409.
 */
final class IllegalTariffTransition extends DomainException
{
    public static function noOpenRow(string $action, string $billingServiceId): self
    {
        return new self(sprintf(
            '%s: no open tariff row for service [%s] in this scope.',
            $action,
            $billingServiceId,
        ));
    }

    public static function endDateNotAfterStart(string $effectiveTo, string $effectiveFrom): self
    {
        return new self(sprintf(
            'EndTariff effective_to [%s] must be strictly after the open row effective_from [%s].',
            $effectiveTo,
            $effectiveFrom,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_tariff_transition';
    }
}
