<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Contracts\Event\PurchaseOrderAmended;
use App\Domains\Procurement\Contracts\Event\PurchaseOrderApproved;
use App\Domains\Procurement\Contracts\Event\PurchaseOrderCancelled;
use App\Domains\Procurement\Contracts\Event\PurchaseOrderIssued;
use App\Domains\Procurement\Database\Factories\PurchaseOrderFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * The Purchase Order aggregate (19-procurement-domain.md §43–§51, §67, §73,
 * §247; ADR-171 D-G / D-H).
 *
 * §44 lifecycle: DRAFT → SUBMITTED → APPROVED → ISSUED → PARTIALLY_RECEIVED →
 * FULLY_RECEIVED (+ CANCELLED from DRAFT | SUBMITTED | APPROVED | ISSUED |
 * PARTIALLY_RECEIVED). The receipt tail (`PARTIALLY_RECEIVED` / `FULLY_RECEIVED`,
 * ADR-172) is rolled ONLY by `RecordPurchaseOrderReceiptHandler` via
 * {@see markReceiptProgress()} — never a human command (Inv 15). ACKNOWLEDGED +
 * CLOSED are Proc.4. Emits `PurchaseOrderApproved` / `PurchaseOrderIssued` /
 * `PurchaseOrderAmended` / `PurchaseOrderCancelled` (the receipt-projection
 * events are deferred — ADR-172 D-L). Lines live on `pcr_po_lines`; the
 * amendment history on `pcr_po_amendments`; the receipt ledger on
 * `pcr_po_receipts` (both append-only).
 *
 * @domain      Procurement
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
 * @property string $po_number
 * @property string $supplier_id
 * @property string|null $requisition_id
 * @property string|null $sourcing_rfq_id
 * @property string|null $sourcing_method
 * @property string|null $contract_id
 * @property string $currency
 * @property string $priority
 * @property bool $emergency
 * @property string|null $emergency_reason
 * @property string $status
 * @property string|null $notes
 * @property string|null $cost_centre_ref
 * @property string|null $budget_ref
 * @property string|null $approved_by_user_id
 * @property CarbonImmutable|null $approved_at
 * @property string|null $issued_by_user_id
 * @property CarbonImmutable|null $issued_at
 * @property string|null $cancellation_reason
 * @property string|null $closed_short_reason
 * @property string|null $closed_short_by_user_id
 * @property CarbonImmutable|null $closed_short_at
 * @property int $amendment_count
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property int $version
 */
final class PurchaseOrder extends AggregateRoot
{
    /** @use HasFactory<PurchaseOrderFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_SUBMITTED = 'SUBMITTED';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_ISSUED = 'ISSUED';

    /** Receipt tail — rolled ONLY by `RecordPurchaseOrderReceiptHandler` (ADR-172 D-F, Inv 15). */
    public const STATUS_PARTIALLY_RECEIVED = 'PARTIALLY_RECEIVED';

    public const STATUS_FULLY_RECEIVED = 'FULLY_RECEIVED';

    public const STATUS_CANCELLED = 'CANCELLED';

    /** P1-10 — the remaining balance will never be delivered; terminal. */
    public const STATUS_CLOSED_SHORT = 'CLOSED_SHORT';

    /** §278 / §309 sourcing method (ADR-173 D-N — amends ADR-171 D-G). */
    public const SOURCING_METHOD_DIRECT = 'DIRECT';

    public const SOURCING_METHOD_REQUISITION = 'REQUISITION';

    public const SOURCING_METHOD_RFQ = 'RFQ';

    public const SOURCING_METHOD_CONTRACT = 'CONTRACT';

    protected $table = 'pcr_purchase_orders';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'po_number', 'supplier_id', 'requisition_id', 'sourcing_rfq_id', 'sourcing_method', 'contract_id', 'currency', 'priority',
        'emergency', 'emergency_reason',
        'status', 'notes', 'cost_centre_ref', 'budget_ref',
        'approved_by_user_id', 'approved_at', 'issued_by_user_id', 'issued_at',
        'cancellation_reason', 'closed_short_reason', 'closed_short_by_user_id', 'closed_short_at',
        'amendment_count',
        'created_by_user_id', 'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): PurchaseOrderFactory
    {
        return PurchaseOrderFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'emergency' => 'boolean',
            'amendment_count' => 'integer',
            'approved_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'closed_short_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function create(
        string $id,
        string $tenantId,
        string $facilityId,
        string $poNumber,
        string $supplierId,
        ?string $requisitionId,
        string $currency,
        string $priority,
        string $createdByUserId,
        bool $emergency,
        ?string $emergencyReason,
        ?string $notes,
        ?string $sourcingRfqId = null,
        ?string $sourcingMethod = null,
        ?string $contractId = null,
        ?string $costCentreRef = null,
        ?string $budgetRef = null,
        ?CarbonImmutable $occurredAt = null,
    ): self {
        $now = CarbonImmutable::now();

        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'po_number' => $poNumber,
            'supplier_id' => $supplierId,
            'requisition_id' => $requisitionId,
            'sourcing_rfq_id' => $sourcingRfqId,
            'sourcing_method' => $sourcingMethod,
            'contract_id' => $contractId,
            'currency' => strtoupper(trim($currency)),
            'priority' => $priority,
            'emergency' => $emergency,
            'emergency_reason' => $emergency && $emergencyReason !== null && trim($emergencyReason) !== '' ? trim($emergencyReason) : null,
            'status' => self::STATUS_DRAFT,
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            'cost_centre_ref' => $costCentreRef !== null && trim($costCentreRef) !== '' ? trim($costCentreRef) : null,
            'budget_ref' => $budgetRef !== null && trim($budgetRef) !== '' ? trim($budgetRef) : null,
            'amendment_count' => 0,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurredAt ?? $now,
            'recorded_at' => $now,
        ]);
    }

    public function submit(): void
    {
        $this->status = self::STATUS_SUBMITTED;
    }

    public function approve(string $approvedByUserId): void
    {
        $this->status = self::STATUS_APPROVED;
        $this->approved_by_user_id = $approvedByUserId;
        $this->approved_at = CarbonImmutable::now();

        $this->recordThat(new PurchaseOrderApproved(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            purchaseOrderId: (string) $this->id,
            poNumber: (string) $this->po_number,
            supplierId: (string) $this->supplier_id,
            currency: (string) $this->currency,
            emergency: (bool) $this->emergency,
            approvedByUserId: $approvedByUserId,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function issue(string $issuedByUserId): void
    {
        $this->status = self::STATUS_ISSUED;
        $this->issued_by_user_id = $issuedByUserId;
        $this->issued_at = CarbonImmutable::now();

        $this->recordThat(new PurchaseOrderIssued(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            purchaseOrderId: (string) $this->id,
            poNumber: (string) $this->po_number,
            supplierId: (string) $this->supplier_id,
            currency: (string) $this->currency,
            emergency: (bool) $this->emergency,
            issuedByUserId: $issuedByUserId,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function recordAmendment(string $amendmentType, int $sequence, string $amendedByUserId): void
    {
        $this->amendment_count = (int) $this->amendment_count + 1; // always dirties the row (T.2 lesson)

        $this->recordThat(new PurchaseOrderAmended(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            purchaseOrderId: (string) $this->id,
            poNumber: (string) $this->po_number,
            amendmentType: $amendmentType,
            amendmentSequence: $sequence,
            amendedByUserId: $amendedByUserId,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * Roll the receipt-tail status (ADR-172 D-F). Called ONLY by
     * `RecordPurchaseOrderReceiptHandler` from the `StockReceived` reaction —
     * never a human command (Inv 15). Records NO event (ADR-172 D-L — the
     * projection events are deferred until a consumer lands); the dirtied
     * `status` bumps `version` on save.
     */
    public function markReceiptProgress(string $status): void
    {
        $this->status = $status;
    }

    /**
     * P1-10 — the supplier will not deliver the outstanding balance. Unlike a
     * cancellation, this is a receiving-tail outcome: what was received stays,
     * and the PO cannot receive more.
     */
    public function closeShort(string $closedByUserId, string $reason): void
    {
        $this->status = self::STATUS_CLOSED_SHORT;
        $this->closed_short_reason = trim($reason) !== '' ? trim($reason) : null;
        $this->closed_short_by_user_id = $closedByUserId;
        $this->closed_short_at = CarbonImmutable::now();
    }

    public function cancel(string $cancelledByUserId, string $reason): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->cancellation_reason = trim($reason) !== '' ? trim($reason) : null;

        $this->recordThat(new PurchaseOrderCancelled(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            purchaseOrderId: (string) $this->id,
            poNumber: (string) $this->po_number,
            supplierId: (string) $this->supplier_id,
            cancelledByUserId: $cancelledByUserId,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
