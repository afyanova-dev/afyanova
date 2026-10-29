<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Project one Inventory goods-receipt onto Purchase-Order fulfilment
 * (19-procurement-domain.md §56, §125–§129; ADR-172 D-D).
 *
 * A SYSTEM command — dispatched ONLY by `OnStockReceived` (never an HTTP path),
 * the `ExpireBedReservation` / IC §7.3 precedent: a plain `final readonly class`,
 * NOT `Auditable`, NOT `AuthorizesAgainst`, NO permission key. `AuthorizeCommand`
 * passes it straight through; tenant RLS is the sole isolation guard (ADR-172
 * D-M). Idempotent under redelivery — `idempotencyKey` is the `StockReceived`
 * eventId (layer 1); `pcr_po_receipts.unique(tenant_id, source_event_id)` is the
 * DB backstop (layer 2).
 *
 * `poNumber` is `StockReceived.poRef` (ADR-172 D-B pins it to the PO number).
 * `poLineRef` is the exact line selected at receiving time; when present it is
 * authoritative and the handler never falls back to an item-code match.
 */
final readonly class RecordPurchaseOrderReceipt
{
    public function __construct(
        public string $poNumber,
        public string $sourceEventId,
        public string $itemRef,
        public ?string $batchRef,
        public string $locationRef,
        public string $movementId,
        public string $movementNumber,
        public string $quantity,
        public string $uom,
        public string $facilityId,
        public string $sourceType,
        public string $occurredAt,
        public ?string $idempotencyKey = null,
        public ?string $poLineRef = null,
        public ?string $goodsReceiptRef = null,
    ) {
        foreach ([
            'sourceEventId' => $sourceEventId, 'itemRef' => $itemRef,
            'locationRef' => $locationRef, 'movementId' => $movementId,
            'facilityId' => $facilityId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordPurchaseOrderReceipt::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($batchRef !== null && ! Str::isUuid($batchRef)) {
            throw new InvalidArgumentException('RecordPurchaseOrderReceipt::$batchRef must be a UUID or null.');
        }

        if ($poLineRef !== null && ! Str::isUuid($poLineRef)) {
            throw new InvalidArgumentException('RecordPurchaseOrderReceipt::$poLineRef must be a UUID or null.');
        }

        if ($goodsReceiptRef !== null && ! Str::isUuid($goodsReceiptRef)) {
            throw new InvalidArgumentException('RecordPurchaseOrderReceipt::$goodsReceiptRef must be a UUID or null.');
        }

        if (trim($poNumber) === '') {
            throw new InvalidArgumentException('RecordPurchaseOrderReceipt::$poNumber must not be blank.');
        }

        if (! is_numeric($quantity) || ! Quantity::isPositive($quantity)) {
            throw new InvalidArgumentException('RecordPurchaseOrderReceipt::$quantity must be a positive decimal string.');
        }

        try {
            CarbonImmutable::parse($occurredAt);
        } catch (Throwable) {
            throw new InvalidArgumentException('RecordPurchaseOrderReceipt::$occurredAt must be an ISO-8601 datetime.');
        }
    }
}
