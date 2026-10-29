<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * P2-03 — a direct purchase order exactly repeats an active direct order for
 * the same facility and supplier. A double-submit or copy-paste is stopped
 * here; a genuine repeat must carry an explicit reason, which the handler
 * writes to the audit trail.
 */
final class DuplicateDirectPurchaseOrder extends DomainException
{
    public static function matches(string $poNumber, string $purchaseOrderId): self
    {
        return new self(sprintf(
            'This is an exact repeat of purchase order %s. If the order is genuinely needed again, add a repeat-order reason and submit it once more.',
            $poNumber,
        ));
    }

    public function errorCode(): string
    {
        return 'duplicate_direct_purchase_order';
    }
}
