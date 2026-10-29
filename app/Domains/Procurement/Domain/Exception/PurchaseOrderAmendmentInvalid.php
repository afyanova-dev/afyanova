<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An `AmendPurchaseOrder` command was structurally invalid — the target line
 * does not belong to the PO, or the line is cancelled (19-procurement-domain.md
 * §67, §68; ADR-171 D-H / D-I). Value-level validation (numeric / date shape)
 * is a constructor `InvalidArgumentException`. Maps to HTTP 409.
 */
final class PurchaseOrderAmendmentInvalid extends DomainException
{
    public static function unknownLine(string $poId, string $lineId): self
    {
        return new self(sprintf('Line [%s] is not a line on purchase order [%s].', $lineId, $poId));
    }

    public static function lineCancelled(string $lineId): self
    {
        return new self(sprintf('Line [%s] is cancelled and cannot be amended.', $lineId));
    }

    public function errorCode(): string
    {
        return 'purchase_order_amendment_invalid';
    }
}
