<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Put an item on a stock count's sheet that the system did not already list
 * (frontend-foundation-inventory-operations.md Phase 8, stock counts).
 *
 * This is how a physical find the system did not know about is recorded: the
 * line is added with the current (usually zero) on-hand as its snapshot, so the
 * variance reads correctly once the counter enters what they found. A count
 * never creates a batch — a batch-tracked item names a batch that already
 * exists, the same rule the adjustment flow follows.
 */
final readonly class AddStockCountLine implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $stockCountId,
        public string $actorId,
        public string $itemId,
        public ?string $batchNumber = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['stockCountId' => $stockCountId, 'actorId' => $actorId, 'itemId' => $itemId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("AddStockCountLine::\${$name} must be a UUID (IC §3).");
            }
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->stockCountId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'inventory.stock_count_line_added',
            actorId: $this->actorId,
            targetType: 'stock_count',
            targetId: $this->stockCountId,
            metadata: [
                'item_id' => $this->itemId,
                'batch_number' => $this->batchNumber !== null && trim($this->batchNumber) !== ''
                    ? trim($this->batchNumber)
                    : null,
            ],
        );
    }
}
