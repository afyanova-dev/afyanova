<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\Quantity;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditFacilityAware;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Set (or clear) the minimum stock level for ONE item AT ONE facility
 * (frontend-foundation-inventory-operations.md Phase 8).
 *
 * The level is a Quantity in the item's BASE unit. `minLevel = 0` means "stop
 * tracking this item at this facility" and removes the row — it never means
 * "reorder at zero". The item master is shared by every facility, so the
 * facility is part of the identity, not an afterthought.
 */
final readonly class SetItemLevel implements Auditable, AuditFacilityAware, AuthorizesAgainst
{
    public readonly string $minLevel;

    public function __construct(
        public string $itemId,
        public string $facilityId,
        public string $actorId,
        string $minLevel,
    ) {
        foreach (['itemId' => $itemId, 'facilityId' => $facilityId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("SetItemLevel::\${$name} must be a UUID.");
            }
        }

        if (! is_numeric($minLevel) || (float) $minLevel < 0) {
            throw new InvalidArgumentException('SetItemLevel::$minLevel must be a non-negative number.');
        }

        $this->minLevel = Quantity::of($minLevel);
    }

    public function policyAbility(): string
    {
        return 'manageItemLevels';
    }

    public function subjectId(): string
    {
        return $this->itemId;
    }

    public function auditFacilityId(): string
    {
        return $this->facilityId;
    }

    public function auditEntry(): AuditEntry
    {
        $cleared = Quantity::compare($this->minLevel, '0') <= 0;

        return new AuditEntry(
            action: $cleared ? 'inventory.item_level_cleared' : 'inventory.item_level_set',
            actorId: $this->actorId,
            targetType: 'inventory_item',
            targetId: $this->itemId,
            metadata: [
                'facility_id' => $this->facilityId,
                'min_level' => $this->minLevel,
            ],
        );
    }
}
