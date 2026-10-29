<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Register an operating room as an operational resource, referencing an
 * existing Organization room (17-theatre-domain.md §17; ADR-150 D-C).
 *
 * Found missing during implementation of ADR-150's original 15-command list:
 * `AssignOperatingRoom` only assigns an EXISTING room to a case, but no
 * command populated `thr_operating_rooms` at all — added here, reusing
 * `theatre.rooms.manage` (no new permission key).
 */
final readonly class CreateOperatingRoom implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $operatingRoomId,
        public string $facilityId,
        public string $orgRoomId,
        public string $actorId,
        public string $label,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($operatingRoomId)) {
            throw new InvalidArgumentException('CreateOperatingRoom::$operatingRoomId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('CreateOperatingRoom::$facilityId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($orgRoomId)) {
            throw new InvalidArgumentException('CreateOperatingRoom::$orgRoomId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CreateOperatingRoom::$actorId must be a UUID (IC §3).');
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException('CreateOperatingRoom::$label must not be empty.');
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
            action: 'theatre.operating_room_created',
            actorId: $this->actorId,
            targetType: 'operating_room',
            targetId: $this->operatingRoomId,
            metadata: [
                'facility_id' => $this->facilityId,
                'org_room_id' => $this->orgRoomId,
                'label' => $this->label,
            ],
        );
    }
}
