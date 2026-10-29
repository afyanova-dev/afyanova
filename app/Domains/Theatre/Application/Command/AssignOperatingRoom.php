<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Assign an existing, AVAILABLE operating room to a surgical case
 * (17-theatre-domain.md §219; ADR-150 D-C). Authorizes against the room
 * (the `AssignBed`/Bed precedent — the resource being committed).
 */
final readonly class AssignOperatingRoom implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $operatingRoomId,
        public string $caseId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($operatingRoomId)) {
            throw new InvalidArgumentException('AssignOperatingRoom::$operatingRoomId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('AssignOperatingRoom::$caseId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AssignOperatingRoom::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'assign';
    }

    public function subjectId(): string
    {
        return $this->operatingRoomId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.operating_room_assigned',
            actorId: $this->actorId,
            targetType: 'operating_room',
            targetId: $this->operatingRoomId,
            metadata: [
                'case_id' => $this->caseId,
            ],
        );
    }
}
