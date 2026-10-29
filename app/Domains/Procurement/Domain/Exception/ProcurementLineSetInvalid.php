<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A requisition / purchase order was created with no lines, or a line's shape
 * is invalid (19-procurement-domain.md §18, §51, §53; ADR-171 D-F / D-H).
 * Maps to HTTP 409.
 */
final class ProcurementLineSetInvalid extends DomainException
{
    public static function empty(string $aggregate): self
    {
        return new self(sprintf('A %s must have at least one line.', $aggregate));
    }

    public static function serviceLineNeedsDescription(int $lineNo): self
    {
        return new self(sprintf('Line %d is a SERVICE line and requires a non-blank description (§53; ADR-171).', $lineNo));
    }

    public static function stockLineNeedsItemRef(int $lineNo): self
    {
        return new self(sprintf('Line %d is a STOCK line and requires an inventory item reference (§52; ADR-171).', $lineNo));
    }

    /**
     * A line named a unit the item is neither stocked nor bought in. The base
     * unit is a property of the item; a buying unit must be the item's declared
     * pack (inventory-item-master-spec.md §7).
     */
    public static function unknownUnit(int $lineNo, string $unit, string $baseUom, ?string $packUnit): self
    {
        return new self($packUnit === null
            ? sprintf(
                'Line %d says [%s], but this item is stocked in [%s] and has no buying unit.',
                $lineNo,
                $unit,
                $baseUom,
            )
            : sprintf(
                'Line %d says [%s], but this item is stocked in [%s] and bought in [%s].',
                $lineNo,
                $unit,
                $baseUom,
                $packUnit,
            ));
    }

    public static function packSizeRequired(int $lineNo, string $unit): self
    {
        return new self(sprintf(
            'Line %d is in [%s] — say how many base units are in one [%s] (or set a default buying unit on the item).',
            $lineNo,
            $unit,
            $unit,
        ));
    }

    public static function packSizeNotPositive(int $lineNo): self
    {
        return new self(sprintf(
            'Line %d needs a buying-unit size above zero — say how many base units are in one unit.',
            $lineNo,
        ));
    }

    public static function packSizeOnBaseUnit(int $lineNo): self
    {
        return new self(sprintf(
            'Line %d is in the base unit, so it must not carry a pack size.',
            $lineNo,
        ));
    }

    public function errorCode(): string
    {
        return 'procurement_line_set_invalid';
    }
}
