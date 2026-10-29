<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A stock-adjustment status transition was illegal
 * (18-inventory-and-warehousing-domain.md §94, §95, §250; ADR-170 D-H).
 * Maps to HTTP 409.
 */
final class IllegalStockAdjustmentTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on stock adjustment currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_stock_adjustment_transition';
    }
}
