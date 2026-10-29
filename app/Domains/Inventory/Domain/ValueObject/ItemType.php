<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The closed, behaviour-driving item-type set for an Item Master entry
 * (18-inventory-and-warehousing-domain.md §9; ADR-170 D-C).
 *
 * Set-validated on the write path (the `BillingServiceCategory` precedent —
 * NOT a PHP enum, NOT #[TypeScript]). `MEDICATION` is the only value with
 * behavioural meaning today: Pharmacy's `Dispense` / `AcceptTranscription`
 * reject any item whose type is not `MEDICATION` (§181). The rest are grouping.
 *
 * Design + Tanzania rationale: `inventory-item-master-spec.md` §4.
 */
final class ItemType
{
    public const ALL = [
        'MEDICATION',
        'SUPPLY',
        'PPE',
        'EQUIPMENT',
        'DEVICE',
        'CONSUMABLE',
    ];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid inventory item type [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $value;
    }
}
