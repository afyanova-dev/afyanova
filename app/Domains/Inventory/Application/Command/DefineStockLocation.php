<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditFacilityAware;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class DefineStockLocation implements Auditable, AuditFacilityAware, AuthorizesAgainst
{
    public function __construct(
        public string $locationId,
        public string $actorId,
        public string $facilityId,
        public string $code,
        public string $name,
        public string $locationType,
        public ?string $parentLocationId = null,
        public ?string $departmentId = null,
    ) {
        foreach (['locationId' => $locationId, 'actorId' => $actorId, 'facilityId' => $facilityId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("DefineStockLocation::\${$name} must be a UUID.");
            }
        }

        if ($parentLocationId !== null && ! Str::isUuid($parentLocationId)) {
            throw new InvalidArgumentException('DefineStockLocation::$parentLocationId must be a UUID.');
        }
        if ($departmentId !== null && ! Str::isUuid($departmentId)) {
            throw new InvalidArgumentException('DefineStockLocation::$departmentId must be a UUID.');
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
            action: 'inventory.stock_location_defined',
            actorId: $this->actorId,
            facilityId: $this->facilityId,
            targetType: 'inventory_stock_location',
            targetId: $this->locationId,
            metadata: [
                'code' => strtoupper(trim($this->code)),
                'location_type' => strtoupper(trim($this->locationType)),
            ],
        );
    }
}
