<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Start a physical stock count at ONE location
 * (frontend-foundation-inventory-operations.md Phase 8, stock counts).
 *
 * The handler snapshots everything the system believes is at the location into
 * the count's lines, so the sheet is ready to be walked. Only one count may be
 * open at a location at a time.
 *
 * `$stockCountId` is client-minted (UUIDv7). `$locationId` decides the facility.
 */
final readonly class StartStockCount implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $stockCountId,
        public string $actorId,
        public string $locationId,
        public ?string $notes = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['stockCountId' => $stockCountId, 'actorId' => $actorId, 'locationId' => $locationId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("StartStockCount::\${$name} must be a UUID (IC §3).");
            }
        }
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'inventory.stock_count_started',
            actorId: $this->actorId,
            targetType: 'stock_count',
            targetId: $this->stockCountId,
            metadata: [
                'location_id' => $this->locationId,
            ],
        );
    }
}
