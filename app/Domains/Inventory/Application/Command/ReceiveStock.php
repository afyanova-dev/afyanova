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
 * Record a goods receipt — stock physically received into a location
 * (18-inventory-and-warehousing-domain.md §52, §55, §159; ADR-170 D-L).
 *
 * `$movementId` is client-minted (UUIDv7). `facility_id` is taken from the
 * LOCATION by the handler, never from this command. `source_type` is open-coded
 * (RECEIPT-side seeds: PROCUREMENT | DONATION | OPENING_BALANCE | OTHER).
 * `po_ref` and `po_line_ref` are OPAQUE Procurement identifiers (no FK).
 * `po_line_ref` is the exact line the receiver selected; it is authoritative
 * over any item-code match. Idempotent under retry (§235 / §311 —
 * `?string $idempotencyKey`).
 */
final readonly class ReceiveStock implements Auditable, AuthorizesAgainst
{
    /** ADR-132 — beyond this, a backdated receipt must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $movementId,
        public string $actorId,
        public string $itemId,
        public string $locationId,
        public string $quantity,
        public string $sourceType = 'PROCUREMENT',
        public ?string $poRef = null,
        public ?string $poLineRef = null,
        public ?string $batchNumber = null,
        public ?string $expiryDate = null,
        public ?string $manufactureDate = null,
        public ?string $supplierRef = null,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
        public ?string $enteredUnit = null,
        public ?string $enteredQuantity = null,
        public ?string $packSize = null,
    ) {
        foreach (['movementId' => $movementId, 'actorId' => $actorId, 'itemId' => $itemId, 'locationId' => $locationId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("ReceiveStock::\${$name} must be a UUID (IC §3).");
            }
        }

        if (! is_numeric($quantity) || ! Quantity::isPositive($quantity)) {
            throw new InvalidArgumentException('ReceiveStock::$quantity must be a positive decimal string.');
        }

        if (trim($sourceType) === '') {
            throw new InvalidArgumentException('ReceiveStock::$sourceType must not be blank.');
        }

        if ($poLineRef !== null) {
            if (! Str::isUuid($poLineRef)) {
                throw new InvalidArgumentException('ReceiveStock::$poLineRef must be a UUID or null.');
            }

            if ($poRef === null || trim($poRef) === '') {
                throw new InvalidArgumentException('ReceiveStock::$poRef is required when $poLineRef is given.');
            }
        }

        // "What arrived" record (inventory-item-master-spec.md §7): all three,
        // or none. When the buying unit is named, how many, and the pack size
        // actually delivered, are both required — the handler then proves they
        // equal `$quantity`.
        $enteredUnit = $enteredUnit !== null ? trim($enteredUnit) : null;
        $hasEntered = $enteredUnit !== null && $enteredUnit !== '';

        if ($hasEntered) {
            if ($enteredQuantity === null || ! is_numeric($enteredQuantity) || ! Quantity::isPositive($enteredQuantity)) {
                throw new InvalidArgumentException('ReceiveStock::$enteredQuantity must be a positive decimal when $enteredUnit is given.');
            }
        } elseif (($enteredQuantity !== null && trim($enteredQuantity) !== '')
            || ($packSize !== null && trim($packSize) !== '')) {
            throw new InvalidArgumentException('ReceiveStock::$enteredUnit is required when $enteredQuantity or $packSize is given.');
        }

        if ($packSize !== null && (! is_numeric($packSize) || ! Quantity::isPositive($packSize))) {
            throw new InvalidArgumentException('ReceiveStock::$packSize must be a positive decimal or null.');
        }

        foreach (['expiryDate' => $expiryDate, 'manufactureDate' => $manufactureDate] as $name => $value) {
            if ($value !== null && trim($value) !== '') {
                try {
                    CarbonImmutable::parse($value);
                } catch (Throwable) {
                    throw new InvalidArgumentException("ReceiveStock::\${$name} must be a date or null.");
                }
            }
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('ReceiveStock::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('ReceiveStock::$occurredAt must not be in the future.');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'ReceiveStock::$reason is required when $occurredAt is more than %d hours in the past.',
                    self::BACKDATE_REASON_THRESHOLD_HOURS,
                ));
            }
        }
    }

    public function policyAbility(): string
    {
        return 'receive';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'inventory.stock_received',
            actorId: $this->actorId,
            targetType: 'stock_movement',
            targetId: $this->movementId,
            metadata: [
                'item_id' => $this->itemId,
                'location_id' => $this->locationId,
                'quantity' => $this->quantity,
                'source_type' => strtoupper(trim($this->sourceType)),
                'po_ref' => $this->poRef,
                'po_line_ref' => $this->poLineRef,
            ],
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
