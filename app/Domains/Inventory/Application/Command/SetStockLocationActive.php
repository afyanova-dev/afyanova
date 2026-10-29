<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditFacilityAware;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class SetStockLocationActive implements Auditable, AuditFacilityAware, AuthorizesAgainst
{
    public function __construct(
        public string $locationId,
        public string $actorId,
        public string $facilityId,
        public bool $active,
    ) {
        foreach (['locationId' => $locationId, 'actorId' => $actorId, 'facilityId' => $facilityId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("SetStockLocationActive::\${$name} must be a UUID.");
            }
        }
    }

    public function policyAbility(): string
    {
        return 'manageLocations';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditFacilityId(): ?string
    {
        return $this->facilityId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: $this->active ? 'inventory.stock_location_activated' : 'inventory.stock_location_deactivated',
            actorId: $this->actorId,
            facilityId: $this->facilityId,
            targetType: 'inventory_stock_location',
            targetId: $this->locationId,
            metadata: ['active' => $this->active],
        );
    }
}
