<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Model;

use App\Domains\Inventory\Contracts\Event\StockReservationReleased;
use App\Domains\Inventory\Contracts\Event\StockReserved;
use App\Domains\Inventory\Database\Factories\StockReservationFactory;
use App\Domains\Inventory\Domain\Quantity;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * The Stock Reservation aggregate (18-inventory-and-warehousing-domain.md §32,
 * §112–§116, §150, §217, §303, §245; ADR-180 D-C).
 *
 * A reservation COMMITS stock to a consumer without moving it (§115): the
 * matching `StockBalance::reserve()` / `release()` maintains `reserved_qty`;
 * this aggregate owns the lifecycle and the events. ACTIVE → {FULFILLED,
 * RELEASED, EXPIRED, CANCELLED}. `status` is an open-coded string constant (the
 * `StockAdjustment` precedent — NOT a PHP enum, NOT `#[TypeScript]`).
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
 * @property string $reserved_qty
 * @property string $consumer_type
 * @property string|null $consumer_ref
 * @property string|null $reason
 * @property CarbonImmutable|null $hold_until
 * @property string $status
 * @property string|null $fulfilled_by_movement_id
 * @property string|null $released_reason
 * @property string $requested_by_user_id
 * @property string|null $released_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property int $version
 */
final class StockReservation extends AggregateRoot
{
    /** @use HasFactory<StockReservationFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_FULFILLED = 'FULFILLED';

    public const STATUS_RELEASED = 'RELEASED';

    public const STATUS_EXPIRED = 'EXPIRED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'inv_stock_reservations';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'item_id', 'location_id', 'batch_id',
        'reserved_qty', 'consumer_type', 'consumer_ref', 'reason', 'hold_until',
        'status', 'fulfilled_by_movement_id', 'released_reason',
        'requested_by_user_id', 'released_by_user_id',
        'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): StockReservationFactory
    {
        return StockReservationFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reserved_qty' => 'decimal:4',
            'hold_until' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function reserve(
        string $id,
        string $tenantId,
        string $facilityId,
        string $itemId,
        string $locationId,
        ?string $batchId,
        string $quantity,
        string $consumerType,
        ?string $consumerRef,
        ?string $reason,
        ?CarbonImmutable $holdUntil,
        string $requestedByUserId,
    ): self {
        $now = CarbonImmutable::now();

        $reservation = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'item_id' => $itemId,
            'location_id' => $locationId,
            'batch_id' => $batchId,
            'reserved_qty' => Quantity::of($quantity),
            'consumer_type' => $consumerType,
            'consumer_ref' => $consumerRef !== null && trim($consumerRef) !== '' ? trim($consumerRef) : null,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'hold_until' => $holdUntil,
            'status' => self::STATUS_ACTIVE,
            'requested_by_user_id' => $requestedByUserId,
            'occurred_at' => $now,
            'recorded_at' => $now,
        ]);

        $reservation->recordThat(new StockReserved(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            reservationId: $id,
            itemId: $itemId,
            locationId: $locationId,
            batchId: $batchId,
            quantity: Quantity::of($quantity),
            consumerType: $consumerType,
            consumerRef: $reservation->consumer_ref,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $reservation;
    }

    public function fulfill(string $movementId): void
    {
        $this->status = self::STATUS_FULFILLED;
        $this->fulfilled_by_movement_id = $movementId;
        $this->released_reason = self::STATUS_FULFILLED;
        $this->recordReleased(self::STATUS_FULFILLED);
    }

    public function release(string $releasedByUserId): void
    {
        $this->status = self::STATUS_RELEASED;
        $this->released_by_user_id = $releasedByUserId;
        $this->released_reason = self::STATUS_RELEASED;
        $this->recordReleased(self::STATUS_RELEASED);
    }

    public function cancel(string $releasedByUserId, string $reason): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->released_by_user_id = $releasedByUserId;
        $this->released_reason = self::STATUS_CANCELLED;
        $this->reason = trim($reason) !== '' ? trim($reason) : $this->reason;
        $this->recordReleased(self::STATUS_CANCELLED);
    }

    public function expire(): void
    {
        $this->status = self::STATUS_EXPIRED;
        $this->released_reason = self::STATUS_EXPIRED;
        $this->recordReleased(self::STATUS_EXPIRED);
    }

    /** §114 — the hold has lapsed and the sweep should release it. */
    public function isLapsed(): bool
    {
        return $this->hold_until !== null && $this->hold_until->isPast();
    }

    private function recordReleased(string $reason): void
    {
        $this->recordThat(new StockReservationReleased(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            reservationId: (string) $this->id,
            itemId: (string) $this->item_id,
            locationId: (string) $this->location_id,
            batchId: $this->batch_id !== null ? (string) $this->batch_id : null,
            quantity: (string) $this->reserved_qty,
            reason: $reason,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
