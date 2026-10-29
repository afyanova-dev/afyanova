<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Model;

use App\Domains\Inventory\Database\Factories\StockAdjustmentFactory;
use App\Domains\Inventory\Domain\Quantity;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use InvalidArgumentException;

/**
 * The Stock Adjustment workflow aggregate
 * (18-inventory-and-warehousing-domain.md §49, §94, §95, §224, §250; ADR-170 D-H).
 *
 * PENDING_APPROVAL → APPROVED | REJECTED. Emits NO events — the balance emits
 * `StockAdjusted` when the compensating movement is applied on approval (§323).
 * Aggregate-0 shape. `status` is an open-coded string constant on the model
 * (the `ImagingOrder` / `LabOrder` / `SurgicalCase` precedent — Diagnostics and
 * Theatre model every lifecycle status this way, no PHP enum, no
 * `#[TypeScript]`).
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
 * @property string $item_id
 * @property string $location_id
 * @property string|null $batch_id
 * @property string $direction
 * @property string $quantity
 * @property string $reason_code
 * @property string|null $reason_text
 * @property string|null $witness_name
 * @property string $status
 * @property string $requested_by_user_id
 * @property CarbonImmutable $requested_at
 * @property string|null $approved_by_user_id
 * @property CarbonImmutable|null $approved_at
 * @property string|null $rejection_reason
 * @property string|null $resulting_movement_id
 * @property int $version
 */
final class StockAdjustment extends AggregateRoot
{
    /** @use HasFactory<StockAdjustmentFactory> */
    use HasFactory;

    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const DIRECTION_INCREASE = 'INCREASE';

    public const DIRECTION_DECREASE = 'DECREASE';

    protected $table = 'inv_stock_adjustments';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'item_id', 'location_id', 'batch_id',
        'direction', 'quantity', 'reason_code', 'reason_text', 'witness_name',
        'status',
        'requested_by_user_id', 'requested_at',
        'approved_by_user_id', 'approved_at', 'rejection_reason',
        'resulting_movement_id',
        'version',
    ];

    protected static function newFactory(): StockAdjustmentFactory
    {
        return StockAdjustmentFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'requested_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function raise(
        string $id,
        string $tenantId,
        string $facilityId,
        string $itemId,
        string $locationId,
        ?string $batchId,
        string $direction,
        string $quantity,
        string $reasonCode,
        ?string $reasonText,
        string $requestedByUserId,
        CarbonImmutable $requestedAt,
        ?string $witnessName = null,
    ): self {
        $dir = strtoupper(trim($direction));

        if (! in_array($dir, [self::DIRECTION_INCREASE, self::DIRECTION_DECREASE], true)) {
            throw new InvalidArgumentException('StockAdjustment::$direction must be INCREASE|DECREASE (ADR-170 D-H).');
        }

        if (! Quantity::isPositive($quantity)) {
            throw new InvalidArgumentException('StockAdjustment::$quantity must be positive (ADR-170 D-H).');
        }

        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'item_id' => $itemId,
            'location_id' => $locationId,
            'batch_id' => $batchId,
            'direction' => $dir,
            'quantity' => $quantity,
            'reason_code' => strtoupper(trim($reasonCode)),
            'reason_text' => $reasonText !== null && trim($reasonText) !== '' ? trim($reasonText) : null,
            'witness_name' => $witnessName !== null && trim($witnessName) !== '' ? trim($witnessName) : null,
            'status' => self::STATUS_PENDING_APPROVAL,
            'requested_by_user_id' => $requestedByUserId,
            'requested_at' => $requestedAt,
        ]);
    }

    /** Record the approval decision on this row (the movement is posted by the handler). */
    public function markApproved(string $approvedByUserId, string $movementId, CarbonImmutable $approvedAt): void
    {
        $this->status = self::STATUS_APPROVED;
        $this->approved_by_user_id = $approvedByUserId;
        $this->approved_at = $approvedAt;
        $this->resulting_movement_id = $movementId;
    }

    public function markRejected(string $rejectedByUserId, ?string $reason, CarbonImmutable $rejectedAt): void
    {
        $this->status = self::STATUS_REJECTED;
        $this->approved_by_user_id = $rejectedByUserId;
        $this->approved_at = $rejectedAt;
        $this->rejection_reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
    }

    public function movementDirection(): string
    {
        return $this->direction === self::DIRECTION_INCREASE
            ? StockMovement::DIRECTION_IN
            : StockMovement::DIRECTION_OUT;
    }
}
