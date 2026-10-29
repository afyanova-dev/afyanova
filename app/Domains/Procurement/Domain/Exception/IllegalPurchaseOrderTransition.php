<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A purchase-order status transition was illegal (19-procurement-domain.md §44,
 * §67, §73; ADR-171 D-G). Maps to HTTP 409.
 */
final class IllegalPurchaseOrderTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on a purchase order in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_purchase_order_transition';
    }
}
