<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\Quantity;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Reserve stock for an intended consumer
 * (18-inventory-and-warehousing-domain.md §112–§116, §150, §217; ADR-180 D-E).
 *
 * Commits `quantity` of an item at a location — increments
 * `inv_stock_balances.reserved_qty`, records the reservation ACTIVE. No physical
 * movement (§115). `$reservationId` is client-minted (UUIDv7). `consumerType`
 * is open-coded (THEATRE_CASE | PHARMACY_ORDER | DEPARTMENT | WARD | MANUAL |
 * OTHER); `consumerRef` is the OPAQUE business-consumer id (§150). `holdUntil`,
 * when given, must be in the future — the `inventory:expire-reservations` sweep
 * releases the hold once it lapses (§114).
 */
final readonly class ReserveStock implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $reservationId,
        public string $actorId,
        public string $itemId,
        public string $locationId,
        public string $quantity,
        public string $consumerType = 'MANUAL',
        public ?string $consumerRef = null,
        public ?string $batchNumber = null,
        public ?string $holdUntil = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['reservationId' => $reservationId, 'actorId' => $actorId, 'itemId' => $itemId, 'locationId' => $locationId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("ReserveStock::\${$name} must be a UUID (IC §3).");
            }
        }

        if (! is_numeric($quantity) || ! Quantity::isPositive($quantity)) {
            throw new InvalidArgumentException('ReserveStock::$quantity must be a positive decimal string.');
        }

        if (trim($consumerType) === '') {
            throw new InvalidArgumentException('ReserveStock::$consumerType must not be blank.');
        }

        if ($holdUntil !== null) {
            try {
                $parsed = CarbonImmutable::parse($holdUntil);
            } catch (Throwable) {
                throw new InvalidArgumentException('ReserveStock::$holdUntil must be an ISO-8601 datetime or null.');
            }

            if ($parsed->lessThanOrEqualTo(CarbonImmutable::now())) {
                throw new InvalidArgumentException('ReserveStock::$holdUntil must be in the future (§114).');
            }
        }
    }

    public function policyAbility(): string
    {
        return 'reserve';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'inventory.stock_reserved',
            actorId: $this->actorId,
            targetType: 'stock_reservation',
            targetId: $this->reservationId,
            metadata: [
                'item_id' => $this->itemId,
                'location_id' => $this->locationId,
                'quantity' => $this->quantity,
                'consumer_type' => strtoupper(trim($this->consumerType)),
                'consumer_ref' => $this->consumerRef !== null && trim($this->consumerRef) !== '' ? trim($this->consumerRef) : null,
            ],
        );
    }
}
