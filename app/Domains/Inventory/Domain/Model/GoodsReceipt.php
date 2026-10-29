<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Model;

use App\Domains\Inventory\Contracts\Event\GoodsReceiptPosted;
use App\Domains\Inventory\Domain\Quantity;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One goods-receipt header (GRN) — one physical delivery, one document, one
 * posting (Phase 1).
 *
 * The header records delivery, supplier, invoice-capture and match-status data;
 * the lines (`inv_goods_receipt_lines`) record accepted/rejected quantities and
 * the stock movement that posted the accepted quantity. A GRN's lines may
 * reference multiple PO lines of the SAME purchase order.
 *
 * VAT/tax fields are deliberately absent (scope decision): invoice data is
 * captured and exported, never posted to a ledger.
 *
 * Emits `GoodsReceiptPosted` once, after its lines have been written.
 *
 * @domain      Inventory
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $receipt_number
 * @property string $source_type
 * @property string|null $po_ref
 * @property string|null $supplier_ref
 * @property string $location_id
 * @property string|null $delivery_note_number
 * @property string|null $delivery_note_missing_reason
 * @property string|null $invoice_number
 * @property CarbonImmutable|null $invoice_date
 * @property string $invoice_status
 * @property string $invoice_currency
 * @property string|null $invoice_total
 * @property CarbonImmutable $received_at
 * @property string $received_by_user_id
 * @property string|null $remarks
 * @property string $status
 * @property string $match_status
 * @property CarbonImmutable|null $matched_at
 * @property string|null $matched_by_user_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class GoodsReceipt extends AggregateRoot
{
    public const SOURCE_PROCUREMENT = 'PROCUREMENT';

    public const SOURCE_DONATION = 'DONATION';

    public const SOURCE_OPENING_BALANCE = 'OPENING_BALANCE';

    public const SOURCE_OTHER = 'OTHER';

    public const INVOICE_RECEIVED = 'RECEIVED';

    public const INVOICE_PENDING = 'PENDING';

    public const INVOICE_NOT_REQUIRED = 'NOT_REQUIRED';

    public const STATUS_POSTED = 'POSTED';

    public const MATCH_UNMATCHED = 'UNMATCHED';

    public const MATCH_MATCHED = 'MATCHED';

    public const MATCH_VARIANCE = 'VARIANCE';

    protected $table = 'inv_goods_receipts';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id', 'receipt_number', 'source_type',
        'po_ref', 'supplier_ref', 'location_id',
        'delivery_note_number', 'delivery_note_missing_reason',
        'invoice_number', 'invoice_date', 'invoice_status', 'invoice_currency', 'invoice_total',
        'received_at', 'received_by_user_id', 'remarks',
        'status', 'match_status', 'matched_at', 'matched_by_user_id',
        'occurred_at', 'recorded_at', 'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_date' => 'immutable_date',
            'invoice_total' => 'decimal:2',
            'received_at' => 'immutable_datetime',
            'matched_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function record(
        string $id,
        string $tenantId,
        string $facilityId,
        string $receiptNumber,
        string $sourceType,
        ?string $poRef,
        ?string $supplierRef,
        string $locationId,
        ?string $deliveryNoteNumber,
        ?string $deliveryNoteMissingReason,
        ?string $invoiceNumber,
        ?string $invoiceDate,
        string $invoiceStatus,
        string $invoiceCurrency,
        ?string $invoiceTotal,
        CarbonImmutable $receivedAt,
        string $receivedByUserId,
        ?string $remarks,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        foreach ([
            'id' => $id,
            'tenantId' => $tenantId,
            'facilityId' => $facilityId,
            'locationId' => $locationId,
            'receivedByUserId' => $receivedByUserId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("GoodsReceipt::\${$name} must be a UUID.");
            }
        }

        $sourceType = strtoupper(trim($sourceType));
        if (! in_array($sourceType, [
            self::SOURCE_PROCUREMENT,
            self::SOURCE_DONATION,
            self::SOURCE_OPENING_BALANCE,
            self::SOURCE_OTHER,
        ], true)) {
            throw new InvalidArgumentException('GoodsReceipt::$sourceType is not recognised.');
        }

        if (trim($receiptNumber) === '') {
            throw new InvalidArgumentException('GoodsReceipt::$receiptNumber must not be blank.');
        }

        $invoiceStatus = strtoupper(trim($invoiceStatus));
        if (! in_array($invoiceStatus, [
            self::INVOICE_RECEIVED,
            self::INVOICE_PENDING,
            self::INVOICE_NOT_REQUIRED,
        ], true)) {
            throw new InvalidArgumentException('GoodsReceipt::$invoiceStatus is not recognised.');
        }

        $invoiceCurrency = strtoupper(trim($invoiceCurrency));
        if (strlen($invoiceCurrency) !== 3) {
            throw new InvalidArgumentException('GoodsReceipt::$invoiceCurrency must be a 3-letter currency code.');
        }

        if ($invoiceTotal !== null) {
            if (! is_numeric($invoiceTotal) || Quantity::isNegative($invoiceTotal)) {
                throw new InvalidArgumentException('GoodsReceipt::$invoiceTotal must be zero or positive.');
            }

            $invoiceTotal = bcadd($invoiceTotal, '0', 2);
        }

        $poRef = $poRef !== null && trim($poRef) !== '' ? trim($poRef) : null;
        if ($sourceType === self::SOURCE_PROCUREMENT && $poRef === null) {
            throw new InvalidArgumentException('GoodsReceipt::$poRef is required for a PROCUREMENT receipt.');
        }

        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'receipt_number' => trim($receiptNumber),
            'source_type' => $sourceType,
            'po_ref' => $poRef,
            'supplier_ref' => $supplierRef !== null && trim($supplierRef) !== '' ? trim($supplierRef) : null,
            'location_id' => $locationId,
            'delivery_note_number' => $deliveryNoteNumber !== null && trim($deliveryNoteNumber) !== '' ? trim($deliveryNoteNumber) : null,
            'delivery_note_missing_reason' => $deliveryNoteMissingReason !== null && trim($deliveryNoteMissingReason) !== '' ? trim($deliveryNoteMissingReason) : null,
            'invoice_number' => $invoiceNumber !== null && trim($invoiceNumber) !== '' ? trim($invoiceNumber) : null,
            'invoice_date' => $invoiceDate !== null && trim($invoiceDate) !== '' ? CarbonImmutable::parse($invoiceDate) : null,
            'invoice_status' => $invoiceStatus,
            'invoice_currency' => $invoiceCurrency,
            'invoice_total' => $invoiceTotal,
            'received_at' => $receivedAt,
            'received_by_user_id' => $receivedByUserId,
            'remarks' => $remarks !== null && trim($remarks) !== '' ? trim($remarks) : null,
            'status' => self::STATUS_POSTED,
            'match_status' => self::MATCH_UNMATCHED,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /**
     * Record the document-level posted fact after the lines have been written.
     *
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
     *     condition: string
     * }>  $lines
     */
    public function markPosted(array $lines): void
    {
        $this->recordThat(new GoodsReceiptPosted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            goodsReceiptId: (string) $this->id,
            receiptNumber: (string) $this->receipt_number,
            sourceType: (string) $this->source_type,
            poRef: $this->po_ref !== null ? (string) $this->po_ref : null,
            supplierRef: $this->supplier_ref !== null ? (string) $this->supplier_ref : null,
            locationId: (string) $this->location_id,
            receivedAt: CarbonImmutable::parse((string) $this->received_at)->toIso8601String(),
            receivedByUserId: (string) $this->received_by_user_id,
            lines: $lines,
            occurredAt: CarbonImmutable::parse((string) $this->occurred_at)->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function attachInvoice(
        string $invoiceNumber,
        ?string $invoiceDate,
        string $invoiceCurrency,
        ?string $invoiceTotal,
    ): void {
        $invoiceNumber = trim($invoiceNumber);
        $invoiceCurrency = strtoupper(trim($invoiceCurrency));

        if ($invoiceNumber === '') {
            throw new InvalidArgumentException('A received invoice must have an invoice number.');
        }

        if (strlen($invoiceCurrency) !== 3) {
            throw new InvalidArgumentException('GoodsReceipt invoice currency must be a 3-letter currency code.');
        }

        if ($invoiceTotal !== null) {
            if (! is_numeric($invoiceTotal) || Quantity::isNegative($invoiceTotal)) {
                throw new InvalidArgumentException('GoodsReceipt invoice total must be zero or positive.');
            }

            $invoiceTotal = bcadd($invoiceTotal, '0', 2);
        }

        $this->invoice_number = $invoiceNumber;
        $this->invoice_date = $invoiceDate !== null && trim($invoiceDate) !== ''
            ? CarbonImmutable::parse($invoiceDate)
            : null;
        $this->invoice_currency = $invoiceCurrency;
        $this->invoice_total = $invoiceTotal;
        $this->invoice_status = self::INVOICE_RECEIVED;
        $this->match_status = self::MATCH_UNMATCHED;
        $this->matched_at = null;
        $this->matched_by_user_id = null;
        $this->version = (int) $this->version + 1;
    }

    public function markInvoiceMatched(string $userId, bool $hasVariance): void
    {
        if ($this->invoice_status !== self::INVOICE_RECEIVED || $this->invoice_number === null) {
            throw new InvalidArgumentException('A supplier invoice must be attached before matching.');
        }
        if (! Str::isUuid($userId)) {
            throw new InvalidArgumentException('GoodsReceipt::$matchedByUserId must be a UUID.');
        }

        $this->match_status = $hasVariance ? self::MATCH_VARIANCE : self::MATCH_MATCHED;
        $this->matched_at = CarbonImmutable::now();
        $this->matched_by_user_id = $userId;
        $this->version = (int) $this->version + 1;
    }
}
