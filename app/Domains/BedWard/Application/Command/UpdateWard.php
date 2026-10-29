<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class UpdateWard implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $wardId,
        public string $actorId,
        public string $name,
        public string $wardType,
        public int $capacity,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($wardId)) {
            throw new InvalidArgumentException('UpdateWard::$wardId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('UpdateWard::$actorId must be a UUID (IC §3).');
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException('UpdateWard::$name must not be empty.');
        }

        if ($capacity <= 0) {
            throw new InvalidArgumentException('UpdateWard::$capacity must be greater than zero.');
        }
    }

    public function policyAbility(): string
    {
        return 'update';
    }

    public function subjectId(): string
    {
        return $this->wardId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'bed_ward.ward_updated',
            actorId: $this->actorId,
            targetType: 'ward',
            targetId: $this->wardId,
            metadata: [
                'name' => $this->name,
                'ward_type' => $this->wardType,
                'capacity' => $this->capacity,
            ],
        );
    }
}
