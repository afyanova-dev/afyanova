<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Lifecycle state of a payer organization
 * (21-insurance-and-payer-domain.md §9; ADR-198 D-G): ACTIVE | INACTIVE.
 *
 * The `BillingServiceStatus` shape (ADR-188 D-A) — a two-state config toggle,
 * not the multi-state Procurement `SupplierLifecycle`. INACTIVE is reversible
 * and never deletes history (§319 / §320). Backend-only string-constant holder.
 */
final class PayerStatus
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
                'Invalid payer status [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $value;
    }

    public static function isValid(string $value): bool
    {
        return in_array(strtoupper(trim($value)), self::ALL, true);
    }
}
