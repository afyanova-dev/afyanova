<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The base stock unit (UoM) set for an Item Master entry
 * (18-inventory-and-warehousing-domain.md §169; ADR-170 D-C).
 *
 * The base unit is the unit that stock balances are kept in; movements
 * snapshot it at post time. Purchase-unit / pack conversion is deferred
 * (`inventory-item-master-spec.md` §7). Set-validated on the write path (the
 * `BillingServiceCategory` precedent).
 */
final class UnitOfMeasure
{
    public const ALL = [
        'TABLET',
        'CAPSULE',
        'VIAL',
        'AMPOULE',
        'BOTTLE',
        'BOX',
        'PACK',
        'PIECE',
        'EACH',
        'PAIR',
        'SACHET',
        'TUBE',
        'ROLL',
        'SET',
        'KIT',
        'ML',
        'L',
        'MG',
        'G',
        'KG',
    ];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid inventory base unit [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $value;
    }
}
