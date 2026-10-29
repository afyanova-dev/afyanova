<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Create a room within a ward (24-bed-and-ward-management-domain.md §9; ADR-148 D-G).
 */
final readonly class CreateRoom implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $roomId,
        public string $wardId,
        public string $actorId,
        public string $name,
        public string $roomType,
        public bool $isolationCapable = false,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($roomId)) {
            throw new InvalidArgumentException('CreateRoom::$roomId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($wardId)) {
            throw new InvalidArgumentException('CreateRoom::$wardId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CreateRoom::$actorId must be a UUID (IC §3).');
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException('CreateRoom::$name must not be empty.');
        }

        if (trim($roomType) === '') {
            throw new InvalidArgumentException('CreateRoom::$roomType must not be empty.');
        }
    }

    public function policyAbility(): string
    {
        return 'createRoom';
    }

    public function subjectId(): string
    {
        return $this->wardId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'bed_ward.room_created',
            actorId: $this->actorId,
            targetType: 'room',
            targetId: $this->roomId,
            metadata: [
                'ward_id' => $this->wardId,
                'name' => $this->name,
                'room_type' => $this->roomType,
                'isolation_capable' => $this->isolationCapable,
            ],
        );
    }
}
