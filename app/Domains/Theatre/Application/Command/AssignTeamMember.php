<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Assign a staff member to a surgical case team (17-theatre-domain.md §41-45;
 * ADR-150 D-C/D-F). `role` is open-coded (SURGEON|ASSISTANT|ANAESTHETIST|
 * SCRUB_NURSE|CIRCULATING_NURSE|OTHER — §41, not strictly validated). No
 * professional-qualification enforcement in T.1 (HR — PHASE 11, ADR-150 D-F).
 */
final readonly class AssignTeamMember implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $assignmentId,
        public string $caseId,
        public string $staffUserId,
        public string $role,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($assignmentId)) {
            throw new InvalidArgumentException('AssignTeamMember::$assignmentId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('AssignTeamMember::$caseId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($staffUserId)) {
            throw new InvalidArgumentException('AssignTeamMember::$staffUserId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AssignTeamMember::$actorId must be a UUID (IC §3).');
        }

        if (trim($role) === '') {
            throw new InvalidArgumentException('AssignTeamMember::$role must not be blank.');
        }
    }

    public function policyAbility(): string
    {
        return 'assignTeamMember';
    }

    public function subjectId(): string
    {
        return $this->caseId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.team_member_assigned',
            actorId: $this->actorId,
            targetType: 'surgical_case',
            targetId: $this->caseId,
            metadata: [
                'assignment_id' => $this->assignmentId,
                'staff_user_id' => $this->staffUserId,
                'role' => $this->role,
            ],
        );
    }
}
