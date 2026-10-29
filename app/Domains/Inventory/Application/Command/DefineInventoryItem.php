<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditFacilityAware;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class DefineInventoryItem implements Auditable, AuditFacilityAware, AuthorizesAgainst
{
    public function __construct(
        public string $itemId,
        public string $actorId,
        public string $code,
        public string $name,
        public string $itemType,
        public string $uom,
        public ?string $description = null,
        public ?string $category = null,
        public bool $trackBatch = false,
        public bool $trackSerial = false,
        public bool $trackExpiry = false,
        public bool $isControlled = false,
        public ?string $packUnit = null,
        public ?string $packSize = null,
    ) {
        foreach (['itemId' => $itemId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("DefineInventoryItem::\${$name} must be a UUID.");
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
            action: 'inventory.item_defined',
            actorId: $this->actorId,
            targetType: 'inventory_item',
            targetId: $this->itemId,
            metadata: [
                'code' => strtoupper(trim($this->code)),
                'item_type' => strtoupper(trim($this->itemType)),
            ],
        );
    }
}
