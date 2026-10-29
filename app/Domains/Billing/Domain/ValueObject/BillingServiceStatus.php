<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Status set for a billing service (20-billing-and-payments-domain.md §236;
 * ADR-188 D-A): ACTIVE | INACTIVE.
 *
 * INACTIVE does NOT touch tariff rows (ADR-188 D-C / F5); `GetEffectiveTariff`
 * is status-agnostic and returns `serviceStatus` for the caller (Bill.3) to gate
 * on. Backend-only string-constant holder.
 */
final class BillingServiceStatus
{
    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    /** @var list<string> */
    public const ALL = [self::ACTIVE, self::INACTIVE];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid billing-service status [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $value;
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
