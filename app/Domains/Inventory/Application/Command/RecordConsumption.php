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
 * Record a stock consumption — stock actually used for an operational /
 * clinical activity (18-inventory-and-warehousing-domain.md §70, §76, §77,
 * §274, §326; ADR-170 D-L).
 *
 * The MANUAL / departmental consumption path in Inv.1. The event-driven path
 * (`ProcedureMaterialsConsumed` → this same handler) is Inv.2b. `source_type`
 * is open-coded (CONSUMPTION-side seeds: CLINICAL_PROCEDURE | WARD | DEPARTMENT
 * | LABORATORY | THEATRE | PHARMACY | MANUAL | OTHER). `source_ref` is the
 * OPAQUE id of the causing activity (§282 — never patient demographics).
 * Idempotent under retry (§235 / §312).
 *
 * ADR-180 D-F — `$reservationId`, when set, fulfils an existing ACTIVE stock
 * reservation: the consumed quantity is also released from
 * `inv_stock_balances.reserved_qty` and the reservation flips FULFILLED. The
 * reservation's item / location / (batch when tracked) must match and its
 * `reserved_qty` must be ≥ the consumed quantity. `null` = the exact Inv.1 path.
 */
final readonly class RecordConsumption implements Auditable, AuthorizesAgainst
{
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $movementId,
        public string $actorId,
        public string $itemId,
        public string $locationId,
        public string $quantity,
        public string $sourceType = 'MANUAL',
        public ?string $sourceRef = null,
        public ?string $batchNumber = null,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $reservationId = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['movementId' => $movementId, 'actorId' => $actorId, 'itemId' => $itemId, 'locationId' => $locationId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordConsumption::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($reservationId !== null && ! Str::isUuid($reservationId)) {
            throw new InvalidArgumentException('RecordConsumption::$reservationId must be a UUID or null (IC §3).');
        }

        if (! is_numeric($quantity) || ! Quantity::isPositive($quantity)) {
            throw new InvalidArgumentException('RecordConsumption::$quantity must be a positive decimal string.');
        }

        if (trim($sourceType) === '') {
            throw new InvalidArgumentException('RecordConsumption::$sourceType must not be blank.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('RecordConsumption::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('RecordConsumption::$occurredAt must not be in the future.');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'RecordConsumption::$reason is required when $occurredAt is more than %d hours in the past.',
                    self::BACKDATE_REASON_THRESHOLD_HOURS,
                ));
            }
        }
    }

    public function policyAbility(): string
    {
        return 'consume';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'inventory.stock_consumed',
            actorId: $this->actorId,
            targetType: 'stock_movement',
            targetId: $this->movementId,
            metadata: [
                'item_id' => $this->itemId,
                'location_id' => $this->locationId,
                'quantity' => $this->quantity,
                'source_type' => strtoupper(trim($this->sourceType)),
                'source_ref' => $this->sourceRef,
            ],
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
