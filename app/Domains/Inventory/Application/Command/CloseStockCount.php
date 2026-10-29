<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\Model\StockCount;
use App\Domains\Inventory\Domain\Quantity;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Finish a stock count — either keep the sheet as a record of what was counted
 * (`COMPLETE`) or throw it away (`DISCARD`)
 * (frontend-foundation-inventory-operations.md Phase 8, stock counts).
 *
 * Completing does NOT change stock. Each counted line that disagrees with the
 * snapshot raises a PENDING_APPROVAL stock adjustment under
 * `reason_code = COUNT_VARIANCE`, and a second person approves it — that approval
 * is the only moment stock moves. `witnessName` is required when a controlled
 * medicine has a shortfall (the same witness rule every controlled decrease
 * follows). Discarding is for a count started by mistake — it raises nothing.
 */
final readonly class CloseStockCount implements Auditable, AuthorizesAgainst
{
    /** @var array<string, numeric-string|null> */
    public array $counts;

    /**
     * @param  array<string, string|int|float|null>  $counts
     */
    public function __construct(
        public string $stockCountId,
        public string $actorId,
        public string $decision,
        public ?string $witnessName = null,
        public ?string $idempotencyKey = null,
        array $counts = [],
    ) {
        foreach (['stockCountId' => $stockCountId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CloseStockCount::\${$name} must be a UUID (IC §3).");
            }
        }

        if (! in_array(strtoupper(trim($decision)), [StockCount::COMPLETION_KEEP, StockCount::COMPLETION_DISCARD], true)) {
            throw new InvalidArgumentException('CloseStockCount::$decision must be COMPLETE|DISCARD.');
        }

        $normalised = [];
        foreach ($counts as $lineId => $quantity) {
            if (! Str::isUuid((string) $lineId)) {
                throw new InvalidArgumentException('CloseStockCount::$counts must be keyed by line UUIDs.');
            }

            if ($quantity === null || (is_string($quantity) && trim($quantity) === '')) {
                $normalised[(string) $lineId] = null;

                continue;
            }

            if (! is_numeric($quantity) || Quantity::compare((string) $quantity, '0') < 0) {
                throw new InvalidArgumentException('CloseStockCount::$counts values must be non-negative decimals (or blank to clear).');
            }

            $normalised[(string) $lineId] = Quantity::of((string) $quantity);
        }

        $this->counts = $normalised;
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->stockCountId;
    }

    /** True when the sheet is kept as a record of what was counted. */
    public function keep(): bool
    {
        return strtoupper(trim($this->decision)) === StockCount::COMPLETION_KEEP;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: $this->keep() ? 'inventory.stock_count_completed' : 'inventory.stock_count_discarded',
            actorId: $this->actorId,
            targetType: 'stock_count',
            targetId: $this->stockCountId,
        );
    }
}
