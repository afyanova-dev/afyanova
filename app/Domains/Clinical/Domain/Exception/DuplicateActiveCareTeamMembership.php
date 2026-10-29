<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `AddCareTeamMember` would have created a SECOND simultaneously-active
 * membership for the same `(tenant, encounter, staff_user_id, team_role)`
 * (12-clinical-domain.md §4.1; ADR-142 D-D / D-T — reconciliation R-3). Maps to
 * HTTP 409 (IC §15).
 *
 * The authoritative guard is the database partial unique index
 * `clin_one_active_membership_per_encounter_staff_role` (`WHERE active_to IS
 * NULL`); the handler pre-checks for a friendly error. HISTORICAL RECURRENCE
 * after the previous membership has ended is NOT a conflict — the ended row is
 * outside the predicate — and neither is the same staff user holding a
 * DIFFERENT `team_role` on the encounter.
 */
final class DuplicateActiveCareTeamMembership extends DomainException
{
    public static function forEncounterStaffRole(string $encounterId, string $staffUserId, string $teamRole): self
    {
        return new self(sprintf(
            'Staff user [%s] already holds an active %s membership on encounter [%s].',
            $staffUserId,
            $teamRole,
            $encounterId,
        ));
    }

    public function errorCode(): string
    {
        return 'duplicate_active_care_team_membership';
    }
}
