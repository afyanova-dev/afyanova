<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\Model\GoodsReceipt;
use App\Domains\Inventory\Domain\Quantity;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Record ONE goods receipt (GRN) for ONE physical delivery (Phase 1).
 *
 * `$receiptId` and every line id are client-minted UUIDv7 values; the command
 * is idempotent under the receiving surface's `$idempotencyKey`. The handler
 * resolves the active location and every item, writes the GRN header and lines,
 * and posts only the ACCEPTED quantity to the stock ledger — all in the bus's
 * single transaction.
 *
 * `$poRef` and `$poLineRef` are OPAQUE Procurement identifiers. A PO delivery
 * carries the exact PO line on every line; Inventory never matches by item code.
 *
 * Invoice fields are captured for later finance export. There are NO VAT/tax
 * fields by scope decision.
 */
final readonly class RecordGoodsReceipt implements Auditable, AuthorizesAgainst
{
    public const MAX_LINES = 500;

    /** ADR-132 — beyond this, a backdated receipt must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    /**
     * @param  list<RecordGoodsReceiptLine>  $lines
     */
    public function __construct(
        public string $receiptId,
        public string $actorId,
        public string $locationId,
        public string $sourceType,
        public array $lines,
        public ?string $poRef = null,
        public ?string $supplierRef = null,
        public ?string $deliveryNoteNumber = null,
        public ?string $deliveryNoteMissingReason = null,
        public ?string $invoiceNumber = null,
        public ?string $invoiceDate = null,
        public string $invoiceStatus = GoodsReceipt::INVOICE_RECEIVED,
        public string $invoiceCurrency = 'TZS',
        public ?string $invoiceTotal = null,
        public ?string $receivedAt = null,
        public ?string $remarks = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['receiptId' => $receiptId, 'actorId' => $actorId, 'locationId' => $locationId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordGoodsReceipt::\${$name} must be a UUID.");
            }
        }

        if ($idempotencyKey !== null && ! Str::isUuid($idempotencyKey)) {
            throw new InvalidArgumentException('RecordGoodsReceipt::$idempotencyKey must be a UUID or null.');
        }

        $sourceType = strtoupper(trim($sourceType));
        if (! in_array($sourceType, [
            GoodsReceipt::SOURCE_PROCUREMENT,
            GoodsReceipt::SOURCE_DONATION,
            GoodsReceipt::SOURCE_OPENING_BALANCE,
            GoodsReceipt::SOURCE_OTHER,
        ], true)) {
            throw new InvalidArgumentException('RecordGoodsReceipt::$sourceType is not recognised.');
        }

        $poRef = $poRef !== null && trim($poRef) !== '' ? trim($poRef) : null;

        if ($lines === []) {
            throw new InvalidArgumentException('RecordGoodsReceipt::$lines must contain at least one line.');
        }

        if (count($lines) > self::MAX_LINES) {
            throw new InvalidArgumentException(sprintf('RecordGoodsReceipt supports at most %d lines.', self::MAX_LINES));
        }

        $seenLines = [];
        foreach ($lines as $line) {
            if (isset($seenLines[$line->receiptLineId])) {
                throw new InvalidArgumentException('RecordGoodsReceipt::$lines contains a duplicate receipt line id.');
            }
            $seenLines[$line->receiptLineId] = true;

            if ($sourceType === GoodsReceipt::SOURCE_PROCUREMENT) {
                if ($poRef === null) {
                    throw new InvalidArgumentException('RecordGoodsReceipt::$poRef is required for a PROCUREMENT receipt.');
                }

                if ($line->poLineRef === null) {
                    throw new InvalidArgumentException('Every line of a PROCUREMENT receipt must carry its purchase-order line.');
                }
            } elseif ($poRef !== null || $line->poLineRef !== null) {
                throw new InvalidArgumentException('A purchase-order reference is only valid for a PROCUREMENT receipt.');
            }
        }

        $invoiceStatus = strtoupper(trim($invoiceStatus));
        if (! in_array($invoiceStatus, [
            GoodsReceipt::INVOICE_RECEIVED,
            GoodsReceipt::INVOICE_PENDING,
            GoodsReceipt::INVOICE_NOT_REQUIRED,
        ], true)) {
            throw new InvalidArgumentException('RecordGoodsReceipt::$invoiceStatus is not recognised.');
        }

        $invoiceCurrency = strtoupper(trim($invoiceCurrency));
        if (strlen($invoiceCurrency) !== 3) {
            throw new InvalidArgumentException('RecordGoodsReceipt::$invoiceCurrency must be a 3-letter currency code.');
        }

        if ($invoiceTotal !== null && (! is_numeric($invoiceTotal) || Quantity::isNegative($invoiceTotal))) {
            throw new InvalidArgumentException('RecordGoodsReceipt::$invoiceTotal must be zero or positive or null.');
        }

        if ($invoiceDate !== null && trim($invoiceDate) !== '') {
            try {
                CarbonImmutable::parse($invoiceDate);
            } catch (Throwable) {
                throw new InvalidArgumentException('RecordGoodsReceipt::$invoiceDate must be a date or null.');
            }
        }

        if ($receivedAt !== null) {
            try {
                $received = CarbonImmutable::parse($receivedAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('RecordGoodsReceipt::$receivedAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($received->greaterThan($now)) {
                throw new InvalidArgumentException('RecordGoodsReceipt::$receivedAt must not be in the future.');
            }

            if ($received->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'RecordGoodsReceipt::$reason is required when $receivedAt is more than %d hours in the past.',
                    self::BACKDATE_REASON_THRESHOLD_HOURS,
                ));
            }
        }
    }

    public function policyAbility(): string
    {
        return 'receive';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'inventory.goods_receipt_posted',
            actorId: $this->actorId,
            targetType: 'goods_receipt',
            targetId: $this->receiptId,
            metadata: [
                'location_id' => $this->locationId,
                'source_type' => strtoupper(trim($this->sourceType)),
                'po_ref' => $this->poRef,
                'line_count' => count($this->lines),
                'invoice_status' => strtoupper(trim($this->invoiceStatus)),
            ],
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
