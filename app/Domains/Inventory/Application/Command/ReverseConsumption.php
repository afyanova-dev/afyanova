<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reverse a prior stock consumption — the "corrective inverse" of
 * {@see RecordConsumption} (18-inventory-and-warehousing-domain.md §84, §326;
 * ADR-185 D-C).
 *
 * Appends a compensating IN movement (`movement_type = CONSUMPTION_REVERSAL`)
 * that references the exact original `CONSUMPTION` OUT via `reverses_movement_id`
 * and puts the quantity back on hand. NO 2-actor approval — deliberately distinct
 * from the discretionary `CreateStockAdjustment` flow. NEVER `InsufficientStock`
 * (it is an increase). One reversal per movement (the unique partial index).
 *
 * `$reversalMovementId` is client-minted (UUIDv7). `$sourceRef` is the OPAQUE id
 * of the causing activity (a pharmacy dispensing-reversal id — §282). First
 * caller: Pharmacy `ReverseDispensingHandler` (ADR-185 D-D).
 */
final readonly class ReverseConsumption implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $reversalMovementId,
        public string $actorId,
        public string $originalMovementId,
        public ?string $sourceRef = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach ([
            'reversalMovementId' => $reversalMovementId,
            'actorId' => $actorId,
            'originalMovementId' => $originalMovementId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("ReverseConsumption::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($reversalMovementId === $originalMovementId) {
            throw new InvalidArgumentException('ReverseConsumption: a movement cannot reverse itself.');
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
            action: 'inventory.stock_consumption_reversed',
            actorId: $this->actorId,
            targetType: 'stock_movement',
            targetId: $this->reversalMovementId,
            metadata: [
                'original_movement_id' => $this->originalMovementId,
                'source_ref' => $this->sourceRef,
            ],
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
