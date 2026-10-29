<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditFacilityAware;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class SetInventoryItemActive implements Auditable, AuditFacilityAware, AuthorizesAgainst
{
    public function __construct(
        public string $itemId,
        public string $actorId,
        public bool $active,
    ) {
        foreach (['itemId' => $itemId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("SetInventoryItemActive::\${$name} must be a UUID.");
            }
        }
    }

    public function policyAbility(): string
    {
        return 'manageItems';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditFacilityId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: $this->active ? 'inventory.item_activated' : 'inventory.item_deactivated',
            actorId: $this->actorId,
            targetType: 'inventory_item',
            targetId: $this->itemId,
            metadata: ['active' => $this->active],
        );
    }
}
