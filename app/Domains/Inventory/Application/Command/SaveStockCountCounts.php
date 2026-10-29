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
 * Write down the quantities found on a stock count's sheet
 * (frontend-foundation-inventory-operations.md Phase 8, stock counts).
 *
 * `$counts` maps a line id to the counted quantity, or to null / blank to clear
 * it back to "not counted". A counted quantity of `0` is a real count — the
 * shelf is empty — and is NOT the same as leaving the line uncounted.
 *
 * Nothing here changes stock: the count only records what was found, and a
 * variance is corrected afterwards through the two-person stock adjustment.
 */
final readonly class SaveStockCountCounts implements Auditable, AuthorizesAgainst
{
    /** @var array<string, numeric-string|null> */
    public array $counts;

    /**
     * @param  array<string, string|int|float|null>  $counts
     */
    public function __construct(
        public string $stockCountId,
        public string $actorId,
        array $counts,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['stockCountId' => $stockCountId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("SaveStockCountCounts::\${$name} must be a UUID (IC §3).");
            }
        }

        $normalised = [];
        foreach ($counts as $lineId => $quantity) {
            if (! Str::isUuid($lineId)) {
                throw new InvalidArgumentException('SaveStockCountCounts::$counts must be keyed by line UUIDs.');
            }

            if ($quantity === null || (is_string($quantity) && trim($quantity) === '')) {
                $normalised[$lineId] = null;

                continue;
            }

            if (! is_numeric($quantity) || Quantity::compare((string) $quantity, '0') < 0) {
                throw new InvalidArgumentException('SaveStockCountCounts::$counts values must be non-negative decimals (or blank to clear).');
            }

            $normalised[$lineId] = Quantity::of((string) $quantity);
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

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'inventory.stock_count_recorded',
            actorId: $this->actorId,
            targetType: 'stock_count',
            targetId: $this->stockCountId,
            metadata: [
                'recorded' => count(array_filter($this->counts, static fn (?string $value): bool => $value !== null)),
            ],
        );
    }
}
