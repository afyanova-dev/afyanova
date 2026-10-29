<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Exception\ProcurementLineSetInvalid;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Create a purchase order as DRAFT (19-procurement-domain.md §43–§51; ADR-171
 * D-H; ADR-173 D-N). `$purchaseOrderId` is client-minted (UUIDv7). Supplier must
 * be ACTIVE; `requisitionId` is OPTIONAL (a direct PO is valid — F11); when set
 * the requisition must be APPROVED + same facility. `emergency` requires
 * `emergencyReason` + `procurement.emergency.manage` (F15). `facility_id` from
 * the command, validated against the actor's scope (F9).
 *
 * **`rfqId` (ADR-173 D-N)** — OPTIONAL; when set the PO is seeded from
 * `pcr_rfq_awards` for `(rfqId, supplierId)` (the RFQ must be AWARDED and the
 * supplier must hold ≥ 1 award row) and `$lines` MUST be empty — the handler
 * builds the lines.
 *
 * **`contractId` (ADR-176 D-D)** — OPTIONAL and mutually exclusive with `rfqId`;
 * when set each line carries a `contractLineId` (UUID) + positive `quantity`
 * only — the handler seeds `lineType` / item snapshot / `uom` / `unitPrice` from
 * `pcr_contract_lines` (the agreed contract price wins) after checking the
 * contract is ACTIVE + unexpired, belongs to `supplierId`, and each ordered
 * quantity is within the line's `quantity_ceiling`.
 *
 * `sourcing_method` is set from the path used (DIRECT / REQUISITION / RFQ /
 * CONTRACT).
 *
 * **P2-03** — a DIRECT order that exactly repeats an active direct order at the
 * same facility/supplier is refused unless `$duplicateOverrideReason` explains
 * why the repeat is genuine. The override is written to the audit trail.
 *
 * **P2-01** — `$costCentreRef` / `$budgetRef` are optional reporting references;
 * when the order is raised from a requisition they default to the request's
 * values.
 *
 * @param  array<int, array<string, mixed>>  $lines
 */
final readonly class CreatePurchaseOrder implements Auditable, AuthorizesAgainst
{
    /** @var list<array<string, mixed>> */
    public array $lines;

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function __construct(
        public string $purchaseOrderId,
        public string $actorId,
        public string $facilityId,
        public string $supplierId,
        array $lines,
        public ?string $requisitionId = null,
        public string $currency = 'TZS',
        public string $priority = 'ROUTINE',
        public bool $emergency = false,
        public ?string $emergencyReason = null,
        public ?string $notes = null,
        public ?string $rfqId = null,
        public ?string $contractId = null,
        public ?string $costCentreRef = null,
        public ?string $budgetRef = null,
        public ?string $duplicateOverrideReason = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['purchaseOrderId' => $purchaseOrderId, 'actorId' => $actorId, 'facilityId' => $facilityId, 'supplierId' => $supplierId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CreatePurchaseOrder::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($requisitionId !== null && ! Str::isUuid($requisitionId)) {
            throw new InvalidArgumentException('CreatePurchaseOrder::$requisitionId must be a UUID or null (IC §3).');
        }

        if ($rfqId !== null && ! Str::isUuid($rfqId)) {
            throw new InvalidArgumentException('CreatePurchaseOrder::$rfqId must be a UUID or null (IC §3).');
        }

        if ($contractId !== null && ! Str::isUuid($contractId)) {
            throw new InvalidArgumentException('CreatePurchaseOrder::$contractId must be a UUID or null (IC §3).');
        }

        if ($rfqId !== null && $contractId !== null) {
            throw new InvalidArgumentException('CreatePurchaseOrder::$rfqId and $contractId are mutually exclusive — a PO is sourced from an award XOR a contract.');
        }

        $lines = array_values($lines);

        if ($rfqId !== null) {
            if ($lines !== []) {
                throw new InvalidArgumentException('CreatePurchaseOrder::$lines must be empty when $rfqId is set — the lines are seeded from the award.');
            }
        } elseif ($contractId !== null) {
            if ($lines === []) {
                throw ProcurementLineSetInvalid::empty('purchase order');
            }
            foreach ($lines as $i => $line) {
                $ref = $line['contractLineId'] ?? null;
                if (! is_string($ref) || ! Str::isUuid($ref)) {
                    throw new InvalidArgumentException(sprintf('CreatePurchaseOrder line %d needs a `contractLineId` UUID when $contractId is set.', $i + 1));
                }
                $qty = $line['quantity'] ?? null;
                if ($qty === null || ! is_numeric((string) $qty) || (float) $qty <= 0) {
                    throw new InvalidArgumentException(sprintf('CreatePurchaseOrder line %d needs a positive `quantity`.', $i + 1));
                }
            }
        } else {
            if ($lines === []) {
                throw ProcurementLineSetInvalid::empty('purchase order');
            }

            foreach ($lines as $i => $line) {
                $price = $line['unitPrice'] ?? null;
                if ($price === null || ! is_numeric((string) $price)) {
                    throw new InvalidArgumentException(sprintf('CreatePurchaseOrder line %d requires a numeric unitPrice (§51).', $i + 1));
                }
            }
        }

        if (trim($currency) === '' || trim($priority) === '') {
            throw new InvalidArgumentException('CreatePurchaseOrder::$currency and $priority must not be blank.');
        }

        if ($emergency && ($emergencyReason === null || trim($emergencyReason) === '')) {
            throw new InvalidArgumentException('CreatePurchaseOrder::$emergencyReason is required when $emergency is true (§27; ADR-171 F15).');
        }

        $this->lines = $lines;
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.order_created',
            actorId: $this->actorId,
            targetType: 'purchase_order',
            targetId: $this->purchaseOrderId,
            metadata: [
                'facility_id' => $this->facilityId,
                'supplier_id' => $this->supplierId,
                'line_count' => count($this->lines),
                'emergency' => $this->emergency,
                'cost_centre_ref' => $this->costCentreRef,
                'budget_ref' => $this->budgetRef,
                'duplicate_override' => $this->duplicateOverrideReason !== null
                    && trim($this->duplicateOverrideReason) !== '',
            ],
        );
    }
}
