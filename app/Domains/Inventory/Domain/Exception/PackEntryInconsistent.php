<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A receipt's "what arrived" record (entered unit, entered quantity, pack size)
 * does not agree with the base-unit quantity being posted, or names a unit the
 * item is neither stocked nor bought in (`inventory-item-master-spec.md` §7).
 *
 * `quantity` is always the base-unit truth; the entered record is the honest
 * note of how the goods arrived. The two must line up — a receipt that says
 * "3 BOX of 100" but posts 250 tablets is a data-entry contradiction, not a
 * rounding difference.
 */
final class PackEntryInconsistent extends DomainException
{
    public static function unitNotUsed(string $itemCode, string $baseUom, ?string $packUnit, string $enteredUnit): self
    {
        return new self($packUnit === null
            ? sprintf(
                'Item [%s] is stocked in [%s] and has no buying unit, so [%s] cannot be received against it.',
                $itemCode,
                $baseUom,
                $enteredUnit,
            )
            : sprintf(
                'Item [%s] is stocked in [%s] and bought in [%s]; [%s] is neither.',
                $itemCode,
                $baseUom,
                $packUnit,
                $enteredUnit,
            ));
    }

    public static function quantityMismatch(string $entered, string $base): self
    {
        return new self(sprintf(
            'The received pack record does not match the base quantity: [%s] base units were entered against [%s] in stock.',
            $entered,
            $base,
        ));
    }

    public static function missingPackSize(string $packUnit): self
    {
        return new self(sprintf(
            'A receipt in [%s] needs the pack size actually delivered (how many base units are in one [%s]).',
            $packUnit,
            $packUnit,
        ));
    }

    public function errorCode(): string
    {
        return 'pack_entry_inconsistent';
    }
}
