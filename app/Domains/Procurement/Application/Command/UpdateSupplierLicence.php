<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class UpdateSupplierLicence implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $supplierId,
        public string $actorId,
        public ?string $licenceNumber,
        public ?string $licenceAuthority,
        public ?string $licenceExpiryDate,
    ) {
        if (! Str::isUuid($supplierId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('UpdateSupplierLicence ids must be UUIDs.');
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->supplierId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.supplier_licence_updated',
            actorId: $this->actorId,
            targetType: 'supplier',
            targetId: $this->supplierId,
        );
    }
}
