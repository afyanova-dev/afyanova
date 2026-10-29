<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Create an inpatient ward (24-bed-and-ward-management-domain.md §9; ADR-148 D-G).
 */
final readonly class CreateWard implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $wardId,
        public string $facilityId,
        public string $actorId,
        public string $code,
        public string $name,
        public string $wardType,
        public int $capacity,
        public ?string $capabilityLink = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($wardId)) {
            throw new InvalidArgumentException('CreateWard::$wardId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('CreateWard::$facilityId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CreateWard::$actorId must be a UUID (IC §3).');
        }

        if (trim($code) === '') {
            throw new InvalidArgumentException('CreateWard::$code must not be empty.');
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException('CreateWard::$name must not be empty.');
        }

        if ($capacity <= 0) {
            throw new InvalidArgumentException('CreateWard::$capacity must be greater than zero.');
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
            action: 'bed_ward.ward_created',
            actorId: $this->actorId,
            targetType: 'ward',
            targetId: $this->wardId,
            metadata: [
                'facility_id' => $this->facilityId,
                'code' => $this->code,
                'ward_type' => $this->wardType,
                'capacity' => $this->capacity,
            ],
        );
    }
}
