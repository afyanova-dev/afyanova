<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An operation tried to reserve or consume stock from a batch whose
 * `expiry_date` has passed (ADR-170 D-E; the Phase 4 expiry-eligibility
 * decision). Expired batches are excluded from availability and are not
 * dispensable — the gate is authoritative, not a frontend warning. Maps to
 * HTTP 409.
 */
final class StockBatchExpired extends DomainException
{
    public static function forBatch(string $itemId, string $locationId, string $batchNumber, ?string $expiryDate): self
    {
        return new self(sprintf(
            'Batch [%s] of item [%s] at location [%s] expired on %s and cannot be used.',
            $batchNumber,
            $itemId,
            $locationId,
            $expiryDate ?? 'an unrecorded date',
        ));
    }

    public function errorCode(): string
    {
        return 'stock_batch_expired';
    }
}
