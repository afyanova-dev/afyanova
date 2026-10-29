<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Model;

use App\Domains\Inventory\Domain\Exception\StockCountNotAllowed;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;

/**
 * A physical stock count at ONE location (frontend-foundation-inventory-operations.md
 * Phase 8, stock counts).
 *
 * IN_PROGRESS → COMPLETED | DISCARDED. While IN_PROGRESS the counter writes
 * down what they find line by line; completing freezes the sheet as a record of
 * what was counted. The count does NOT change any stock: a variance is put
 * right afterwards through the existing two-person stock adjustment, so the
 * person who counts is never the person who silently corrects the balance.
 *
 * Emits NO events — it is a record, not a movement. Aggregate-0 shape, like
 * `StockAdjustment` and `StockTransfer`. `notes` is free text, ROW-ONLY
 * (ADR-136).
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
 * @property string $count_number
 * @property string $location_id
 * @property string $status
 * @property string|null $notes
 * @property string $started_by_user_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $closed_at
 * @property int $version
 */
final class StockCount extends AggregateRoot
{
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_DISCARDED = 'DISCARDED';

    public const COMPLETION_KEEP = 'COMPLETE';

    public const COMPLETION_DISCARD = 'DISCARD';

    protected $table = 'inv_stock_counts';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'count_number', 'location_id',
        'status', 'notes',
        'started_by_user_id', 'started_at', 'closed_at',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function open(
        string $id,
        string $tenantId,
        string $facilityId,
        string $countNumber,
        string $locationId,
        ?string $notes,
        string $startedByUserId,
        CarbonImmutable $startedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'count_number' => $countNumber,
            'location_id' => $locationId,
            'status' => self::STATUS_IN_PROGRESS,
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            'started_by_user_id' => $startedByUserId,
            'started_at' => $startedAt,
        ]);
    }

    /** Refuse any change to a count that is no longer open. */
    public function assertOpen(): void
    {
        if ($this->status !== self::STATUS_IN_PROGRESS) {
            throw StockCountNotAllowed::notOpen((string) $this->id, (string) $this->status);
        }
    }

    /** Finish the count, keeping the sheet as a record of what was counted. */
    public function complete(CarbonImmutable $at): void
    {
        $this->assertOpen();

        $this->status = self::STATUS_COMPLETED;
        $this->closed_at = $at;
    }

    /** Throw the count away — it was started by mistake, or the sheet was wrong. */
    public function discard(CarbonImmutable $at): void
    {
        $this->assertOpen();

        $this->status = self::STATUS_DISCARDED;
        $this->closed_at = $at;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }
}
