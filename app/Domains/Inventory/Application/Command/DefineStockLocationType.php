<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditFacilityAware;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class DefineStockLocationType implements Auditable, AuditFacilityAware, AuthorizesAgainst
{
    public function __construct(
        public string $typeId,
        public string $actorId,
        public ?string $locationTypeFacilityId,
        public string $code,
        public string $name,
    ) {
        foreach (['typeId' => $typeId, 'actorId' => $actorId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("DefineStockLocationType::\${$field} must be a UUID.");
            }
        }
        if ($locationTypeFacilityId !== null && ! Str::isUuid($locationTypeFacilityId)) {
            throw new InvalidArgumentException('DefineStockLocationType::$locationTypeFacilityId must be a UUID or null.');
        }
    }

    public function policyAbility(): string
    {
        return 'manageLocationTypes';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditFacilityId(): ?string
    {
        return $this->locationTypeFacilityId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'inventory.stock_location_type_defined',
            actorId: $this->actorId,
            targetType: 'inventory_stock_location_type',
            targetId: $this->typeId,
            metadata: ['code' => strtoupper(trim($this->code))],
        );
    }
}
