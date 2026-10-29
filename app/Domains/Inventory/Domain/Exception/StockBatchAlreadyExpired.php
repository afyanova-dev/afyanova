<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A goods receipt supplied a batch whose `expiry_date` had already passed as of
 * the receipt's effective date (`occurred_at`, or now). A batch must not be
 * received once it is expired — the Phase 0 receipt-source policy; the gate is
 * authoritative, not a frontend warning (ADR-170 D-E / D-L). Maps to HTTP 409.
 *
 * Distinct from {@see StockBatchExpired}, which governs *reserving/consuming*
 * from an already-expired batch ("cannot be used").
 */
final class StockBatchAlreadyExpired extends DomainException
{
    public static function forItem(string $itemId, string $batchNumber, string $expiryDate, string $receivedDate): self
    {
        return new self(sprintf(
            'Batch [%s] of item [%s] expired on %s and cannot be received against a receipt dated %s.',
            $batchNumber,
            $itemId,
            $expiryDate,
            $receivedDate,
        ));
    }

    public function errorCode(): string
    {
        return 'stock_batch_already_expired';
    }
}
