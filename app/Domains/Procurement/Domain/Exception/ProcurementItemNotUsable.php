<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A STOCK line referenced an inventory item that is unknown to Inventory or
 * retired (19-procurement-domain.md §52, §170, Invariant 16; ADR-171 D-F / D-H
 * — F7c). Maps to HTTP 409.
 */
final class ProcurementItemNotUsable extends DomainException
{
    public static function unknown(string $itemRef): self
    {
        return new self(sprintf('Inventory item [%s] is unknown — a STOCK line must reference a canonical inventory item (§52; ADR-171 D-N).', $itemRef));
    }

    public static function retired(string $itemRef): self
    {
        return new self(sprintf('Inventory item [%s] is retired and cannot be procured (§181 / §182; ADR-171 D-F).', $itemRef));
    }

    public function errorCode(): string
    {
        return 'procurement_item_not_usable';
    }
}
