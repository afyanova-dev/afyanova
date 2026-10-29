<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Replace a team assignment with a new staff member in the same role
 * (17-theatre-domain.md §45 "Staff Assignment History"; ADR-150 D-C/D-F) —
 * append-and-mark-replaced, never a hard delete.
 */
final readonly class ReplaceTeamMember implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $newAssignmentId,
        public string $caseId,
        public string $existingAssignmentId,
        public string $newStaffUserId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($newAssignmentId)) {
            throw new InvalidArgumentException('ReplaceTeamMember::$newAssignmentId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('ReplaceTeamMember::$caseId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($existingAssignmentId)) {
            throw new InvalidArgumentException('ReplaceTeamMember::$existingAssignmentId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($newStaffUserId)) {
            throw new InvalidArgumentException('ReplaceTeamMember::$newStaffUserId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ReplaceTeamMember::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'replaceTeamMember';
    }

    public function subjectId(): string
    {
        return $this->caseId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.team_member_replaced',
            actorId: $this->actorId,
            targetType: 'surgical_case',
            targetId: $this->caseId,
            metadata: [
                'existing_assignment_id' => $this->existingAssignmentId,
                'new_assignment_id' => $this->newAssignmentId,
                'new_staff_user_id' => $this->newStaffUserId,
            ],
        );
    }
}
