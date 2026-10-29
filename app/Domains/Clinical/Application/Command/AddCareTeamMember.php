<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ValueObject\TeamRole;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Add one staff member to an encounter's clinical care team
 * (12-clinical-domain.md §4.1, §162; ADR-130, ADR-131, ADR-132, ADR-135,
 * ADR-142 D-D … D-K / D-N … D-S).
 *
 * `$careTeamMembershipId` is client-minted (a UUIDv7) so this `Auditable`
 * command names its own target before the row exists (the `AddProblem` /
 * `RecordAllergy` precedent) and repeat delivery is idempotent. `$actorId` is
 * the authenticated user performing the assignment — the membership's
 * `added_by`. `$staffUserId` is the care-team MEMBER — the handler validates it
 * with `IdentityApi::isStaff()` (ADR-142 D-E). `tenant_id` is resolved from
 * `TenantContext`; `facility_id` and `patient_id` are taken from the ENCOUNTER
 * context by the handler (ADR-142 D-B / D-L), NEVER from this command.
 *
 * `$teamRole` is set-validated against the documented {@see TeamRole} constant
 * list (open coded string, no enum, no `#[TypeScript]`, no DB CHECK — ADR-142
 * D-F). `$isProviderOfRecord` (default false) designates the new membership as
 * the encounter's provider-of-record — the handler accepts `true` ONLY when the
 * encounter has no active provider-of-record (else 409; use
 * `ChangeProviderOfRecord` for a hand-off — ADR-142 D-G).
 *
 * There is NO caller-supplied timestamp: `active_from` is server `now()`
 * (ADR-142 D-I / D-S) — hence NO ADR-132 backdate `reason` and the audit
 * `reason` is always null.
 *
 * Class-level (create) authorization: the bus checks
 * `CareTeamMembershipPolicy::record`. The handler adds the ADR-142 D-N
 * per-encounter-facility refinement.
 */
final readonly class AddCareTeamMember implements Auditable, AuthorizesAgainst
{
    public string $teamRole;

    public function __construct(
        public string $careTeamMembershipId,
        public string $actorId,
        public string $encounterId,
        public string $staffUserId,
        string $teamRole,
        public bool $isProviderOfRecord = false,
        public ?string $idempotencyKey = null,
    ) {
        foreach ([
            'careTeamMembershipId' => $careTeamMembershipId,
            'actorId' => $actorId,
            'encounterId' => $encounterId,
            'staffUserId' => $staffUserId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("AddCareTeamMember::\${$name} must be a UUID (IC §3).");
            }
        }

        $this->teamRole = TeamRole::assert($teamRole); // InvalidArgumentException for an unknown role
    }

    public function policyAbility(): string
    {
        return 'record';
    }

    public function subjectId(): ?string
    {
        return null; // create — class-level check (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.care_team.member_added',
            actorId: $this->actorId,
            targetType: 'care_team_membership',
            targetId: $this->careTeamMembershipId,
            reason: null, // ADR-142 D-S — metadata = [], reason = null always
        );
    }
}
