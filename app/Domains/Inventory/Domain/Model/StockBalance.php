<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Model;

use App\Domains\Inventory\Contracts\Event\StockAdjusted;
use App\Domains\Inventory\Contracts\Event\StockConsumed;
use App\Domains\Inventory\Contracts\Event\StockConsumptionReversed;
use App\Domains\Inventory\Contracts\Event\StockReceived;
use App\Domains\Inventory\Contracts\Event\StockReturned;
use App\Domains\Inventory\Contracts\Event\StockTransferred;
use App\Domains\Inventory\Database\Factories\StockBalanceFactory;
use App\Domains\Inventory\Domain\Exception\InsufficientStock;
use App\Domains\Inventory\Domain\Quantity;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * The materialised Stock Balance for one (item, location, batch) tuple
 * (18-inventory-and-warehousing-domain.md §29–§31, §85, §86, §246, §252, §254;
 * ADR-170 D-F).
 *
 * `balance = Σ movements` — reconcilable against `inv_stock_movements` (§254).
 * The event source for the module: {@see applyReceipt()} / {@see applyConsumption()}
 * / {@see applyAdjustment()} mutate `on_hand_qty` and record the matching
 * PHI-safe event. Concurrency: aggregate-0 optimistic `version` AND
 * `SELECT … FOR UPDATE` in the repository (§302 / §304 / §307).
 *
 * `available = on_hand_qty − reserved_qty` (§30) is computed in the
 * reader/DTO, never stored. `reserved_qty` is always 0 in Inv.1 (the Inv.2
 * `ReserveStock` hook).
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
 * @property string $on_hand_qty
 * @property string $reserved_qty
 * @property int $version
 */
final class StockBalance extends AggregateRoot
{
    /** @use HasFactory<StockBalanceFactory> */
    use HasFactory;

    protected $table = 'inv_stock_balances';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'item_id', 'location_id', 'batch_id',
        'on_hand_qty', 'reserved_qty',
        'version',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'version' => 0,
        'on_hand_qty' => '0',
        'reserved_qty' => '0',
    ];

    protected static function newFactory(): StockBalanceFactory
    {
        return StockBalanceFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'on_hand_qty' => 'decimal:4',
            'reserved_qty' => 'decimal:4',
            'version' => 'integer',
        ];
    }

    /** A fresh, zeroed balance row for a (item, location, batch) tuple. */
    public static function start(
        string $tenantId,
        string $facilityId,
        string $itemId,
        string $locationId,
        ?string $batchId,
    ): self {
        return new self([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'item_id' => $itemId,
            'location_id' => $locationId,
            'batch_id' => $batchId,
            'on_hand_qty' => '0',
            'reserved_qty' => '0',
        ]);
    }

    /** on_hand − reserved (§30). In Inv.1 reserved is always 0. */
    public function available(): string
    {
        return Quantity::sub((string) $this->on_hand_qty, (string) $this->reserved_qty);
    }

    public function applyReceipt(StockMovement $movement): void
    {
        $this->on_hand_qty = Quantity::add((string) $this->on_hand_qty, (string) $movement->quantity);

        $this->recordThat(new StockReceived(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            itemId: (string) $this->item_id,
            locationId: (string) $this->location_id,
            batchId: $this->batch_id !== null ? (string) $this->batch_id : null,
            movementId: (string) $movement->id,
            movementNumber: (string) $movement->movement_number,
            quantity: (string) $movement->quantity,
            uom: (string) $movement->uom,
            sourceType: (string) $movement->source_type,
            poRef: $movement->po_ref !== null ? (string) $movement->po_ref : null,
            occurredAt: CarbonImmutable::parse((string) $movement->occurred_at)->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
            poLineRef: $movement->po_line_ref !== null ? (string) $movement->po_line_ref : null,
            goodsReceiptRef: $movement->goods_receipt_ref !== null ? (string) $movement->goods_receipt_ref : null,
        ));
    }

    /**
     * ADR-180 D-D — `$releaseReserved` (default '0', every Inv.1 caller) is the
     * portion of this consumption that fulfils an existing reservation: it is
     * subtracted from `reserved_qty` alongside `on_hand_qty`, and the Inv 5 guard
     * treats it as no longer reserved. `'0'` = the exact Inv.1 behaviour.
     */
    public function applyConsumption(StockMovement $movement, string $releaseReserved = '0'): void
    {
        $this->assertCanDecrementBy((string) $movement->quantity, $releaseReserved);
        $this->on_hand_qty = Quantity::sub((string) $this->on_hand_qty, (string) $movement->quantity);
        $this->reserved_qty = $this->clampedRelease($releaseReserved);

        $this->recordThat(new StockConsumed(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            itemId: (string) $this->item_id,
            locationId: (string) $this->location_id,
            batchId: $this->batch_id !== null ? (string) $this->batch_id : null,
            movementId: (string) $movement->id,
            movementNumber: (string) $movement->movement_number,
            quantity: (string) $movement->quantity,
            uom: (string) $movement->uom,
            sourceType: (string) $movement->source_type,
            sourceRef: $movement->source_ref !== null ? (string) $movement->source_ref : null,
            occurredAt: CarbonImmutable::parse((string) $movement->occurred_at)->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function applySupplierReturn(StockMovement $movement, string $returnId): void
    {
        $this->assertCanDecrementBy((string) $movement->quantity);
        $this->on_hand_qty = Quantity::sub((string) $this->on_hand_qty, (string) $movement->quantity);

        $this->recordThat(new StockReturned(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            itemId: (string) $this->item_id,
            locationId: (string) $this->location_id,
            batchId: $this->batch_id !== null ? (string) $this->batch_id : null,
            returnId: $returnId,
            movementId: (string) $movement->id,
            movementNumber: (string) $movement->movement_number,
            quantity: (string) $movement->quantity,
            uom: (string) $movement->uom,
            occurredAt: CarbonImmutable::parse((string) $movement->occurred_at)->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * ADR-185 D-C — the "corrective inverse" of {@see applyConsumption()}: the
     * compensating IN movement returns `quantity` to `on_hand_qty`. NEVER an
     * `InsufficientStock` guard — it is an increase. `reserved_qty` is untouched
     * (the original reservation was already FULFILLED and terminal). Records
     * `StockConsumptionReversed`.
     */
    public function applyConsumptionReversal(StockMovement $movement, string $originalMovementId): void
    {
        $this->on_hand_qty = Quantity::add((string) $this->on_hand_qty, (string) $movement->quantity);

        $this->recordThat(new StockConsumptionReversed(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            itemId: (string) $this->item_id,
            locationId: (string) $this->location_id,
            batchId: $this->batch_id !== null ? (string) $this->batch_id : null,
            movementId: (string) $movement->id,
            movementNumber: (string) $movement->movement_number,
            originalMovementId: $originalMovementId,
            quantity: (string) $movement->quantity,
            uom: (string) $movement->uom,
            sourceRef: $movement->source_ref !== null ? (string) $movement->source_ref : null,
            occurredAt: CarbonImmutable::parse((string) $movement->occurred_at)->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * Apply one side of a stock transfer (Phase 8). The movement's
     * `source_ref` is the transfer document id. Only the OUT side can fail —
     * the IN side is an increase — and it must leave the reserved quantity
     * untouched: reserved stock stays at the location it was reserved for.
     */
    public function applyTransfer(StockMovement $movement, string $counterpartLocationId): void
    {
        if ($movement->direction === StockMovement::DIRECTION_OUT) {
            $this->assertCanDecrementBy((string) $movement->quantity);
            $this->on_hand_qty = Quantity::sub((string) $this->on_hand_qty, (string) $movement->quantity);
        } else {
            $this->on_hand_qty = Quantity::add((string) $this->on_hand_qty, (string) $movement->quantity);
        }

        $this->recordThat(new StockTransferred(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            itemId: (string) $this->item_id,
            locationId: (string) $this->location_id,
            counterpartLocationId: $counterpartLocationId,
            batchId: $this->batch_id !== null ? (string) $this->batch_id : null,
            transferId: (string) $movement->source_ref,
            movementId: (string) $movement->id,
            movementNumber: (string) $movement->movement_number,
            direction: (string) $movement->direction,
            quantity: (string) $movement->quantity,
            uom: (string) $movement->uom,
            occurredAt: CarbonImmutable::parse((string) $movement->occurred_at)->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function applyAdjustment(
        StockMovement $movement,
        string $adjustmentId,
        string $reasonCode,
        string $approvedByUserId,
    ): void {
        if ($movement->direction === StockMovement::DIRECTION_OUT) {
            $this->assertCanDecrementBy((string) $movement->quantity);
            $this->on_hand_qty = Quantity::sub((string) $this->on_hand_qty, (string) $movement->quantity);
        } else {
            $this->on_hand_qty = Quantity::add((string) $this->on_hand_qty, (string) $movement->quantity);
        }

        $this->recordThat(new StockAdjusted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            itemId: (string) $this->item_id,
            locationId: (string) $this->location_id,
            batchId: $this->batch_id !== null ? (string) $this->batch_id : null,
            movementId: (string) $movement->id,
            movementNumber: (string) $movement->movement_number,
            adjustmentId: $adjustmentId,
            direction: (string) $movement->direction,
            quantity: (string) $movement->quantity,
            uom: (string) $movement->uom,
            reasonCode: $reasonCode,
            approvedByUserId: $approvedByUserId,
            occurredAt: CarbonImmutable::parse((string) $movement->occurred_at)->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * ADR-180 D-D — commit `$qty` of `reserved_qty` to a planned consumer.
     * Records NO event (the `StockReservation` aggregate owns `StockReserved`);
     * the dirtied `reserved_qty` bumps `version` on save. Inv 17 — the reserve
     * cannot exceed the currently reservable (= `available()`) quantity.
     */
    public function reserve(string $qty): void
    {
        if (Quantity::compare(Quantity::of($qty), $this->available()) > 0) {
            throw InsufficientStock::forReservation(
                (string) $this->item_id,
                (string) $this->location_id,
                $this->available(),
                Quantity::of($qty),
            );
        }

        $this->reserved_qty = Quantity::add((string) $this->reserved_qty, $qty);
    }

    /**
     * ADR-180 D-D — release `$qty` of `reserved_qty` (expire / cancel / release
     * a reservation). Clamped at 0 — a race must never drive it negative.
     * Records NO event.
     */
    public function release(string $qty): void
    {
        $this->reserved_qty = $this->clampedRelease($qty);
    }

    /** @return numeric-string `max(0, reserved_qty − qty)` */
    private function clampedRelease(string $qty): string
    {
        $result = Quantity::sub((string) $this->reserved_qty, $qty);

        return Quantity::isNegative($result) ? Quantity::of('0') : $result;
    }

    /**
     * Inv 5 / §307 — a movement must not drive available (or physical on-hand)
     * stock negative. There is NO emergency override in Inv.1 (§132 deferred).
     * `$releaseReserved` (ADR-180 D-D) is a consumption that fulfils a
     * reservation — that portion of `reserved_qty` no longer counts against
     * available.
     */
    private function assertCanDecrementBy(string $quantity, string $releaseReserved = '0'): void
    {
        $projectedOnHand = Quantity::sub((string) $this->on_hand_qty, $quantity);

        if (Quantity::isNegative($projectedOnHand)) {
            throw InsufficientStock::forDecrement(
                (string) $this->item_id,
                (string) $this->location_id,
                (string) $this->on_hand_qty,
                $quantity,
            );
        }

        $projectedReserved = $this->clampedRelease($releaseReserved);

        if (Quantity::isNegative(Quantity::sub($projectedOnHand, $projectedReserved))) {
            throw InsufficientStock::forDecrement(
                (string) $this->item_id,
                (string) $this->location_id,
                $this->available(),
                $quantity,
            );
        }
    }
}
