<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * The requested dispensing quantity exceeds the available stock for the selected
 * item at the facility (16-pharmacy-domain.md §42, §43; ADR-184 D-H — the
 * friendly pre-check; `InventoryApi::reserveStock` also guards with
 * `InsufficientStock`). Maps to HTTP 409.
 */
final class DispensingStockUnavailable extends DomainException
{
    public static function forItem(string $itemId, string $requested, string $available): self
    {
        return new self(sprintf(
            'Only [%s] of item [%s] is available; cannot dispense [%s] (§42 / §43 — ADR-184 D-H).',
            $available,
            $itemId,
            $requested,
        ));
    }

    public function errorCode(): string
    {
        return 'dispensing_stock_unavailable';
    }
}
