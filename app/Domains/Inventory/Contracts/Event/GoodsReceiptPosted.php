<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Event;

/**
 * One physical delivery was posted as a goods receipt (GRN).
 *
 * This is the document-level fact; stock movements still emit their own
 * `StockReceived` facts per accepted line. Consumers can use this event for
 * receipt/GRN reporting without joining the ledger.
 *
 * PHI-safe: identifiers, coded values, quantities and timestamps only (the
 * ADR-136 deny-list — remarks and condition notes are never carried).
 */
final readonly class GoodsReceiptPosted
{
    /**
     * @param  list<array{
     *     receiptLineId: string,
     *     poLineRef: string|null,
     *     itemId: string,
     *     batchId: string|null,
     *     movementId: string|null,
     *     movementNumber: string|null,
     *     quantityAccepted: string,
     *     quantityRejected: string,
     *     uom: string,
     *     batchNumber: string|null,
     *     expiryDate: string|null,
     *     condition: string,
     *     substitutedForItemId: string|null
     * }>  $lines
     */
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $goodsReceiptId,
        public string $receiptNumber,
        public string $sourceType,
        public ?string $poRef,
        public ?string $supplierRef,
        public string $locationId,
        public string $receivedAt,
        public string $receivedByUserId,
        public array $lines,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
