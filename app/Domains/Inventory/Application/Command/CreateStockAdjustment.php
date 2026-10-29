<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\Quantity;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Raise a stock adjustment for a justified discrepancy
 * (18-inventory-and-warehousing-domain.md §49, §94, §95, §250; ADR-170 D-H).
 * Enters PENDING_APPROVAL — a second authorised actor approves it (§224 /
 * Inv 12). `$adjustmentId` is client-minted (UUIDv7). `direction` is
 * INCREASE | DECREASE; `reason_code` is open-coded (§94 seeds: COUNT_VARIANCE
 * | DAMAGED | LOST | DATA_ERROR | EXPIRY | OTHER).
 */
final readonly class CreateStockAdjustment implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $adjustmentId,
        public string $actorId,
        public string $itemId,
        public string $locationId,
        public string $direction,
        public string $quantity,
        public string $reasonCode,
        public ?string $reasonText = null,
        public ?string $witnessName = null,
        public ?string $batchNumber = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['adjustmentId' => $adjustmentId, 'actorId' => $actorId, 'itemId' => $itemId, 'locationId' => $locationId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CreateStockAdjustment::\${$name} must be a UUID (IC §3).");
            }
        }

        if (! in_array(strtoupper(trim($direction)), ['INCREASE', 'DECREASE'], true)) {
            throw new InvalidArgumentException('CreateStockAdjustment::$direction must be INCREASE|DECREASE.');
        }

        if (! is_numeric($quantity) || ! Quantity::isPositive($quantity)) {
            throw new InvalidArgumentException('CreateStockAdjustment::$quantity must be a positive decimal string.');
        }

        if (trim($reasonCode) === '') {
            throw new InvalidArgumentException('CreateStockAdjustment::$reasonCode must not be blank (§94).');
        }
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
            action: 'inventory.stock_adjustment_created',
            actorId: $this->actorId,
            targetType: 'stock_adjustment',
            targetId: $this->adjustmentId,
            metadata: [
                'item_id' => $this->itemId,
                'location_id' => $this->locationId,
                'direction' => strtoupper(trim($this->direction)),
                'quantity' => $this->quantity,
                'reason_code' => strtoupper(trim($this->reasonCode)),
            ],
        );
    }
}
