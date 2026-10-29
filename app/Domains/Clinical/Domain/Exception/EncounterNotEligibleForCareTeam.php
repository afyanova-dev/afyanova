<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `AddCareTeamMember` was attempted against an encounter whose status does not
 * permit a care-team assignment (12-clinical-domain.md §4.1, §162; ADR-142
 * D-K). Maps to HTTP 409 (IC §15).
 *
 * A care-team member is added only when the encounter status is one of:
 *
 *   - `PLANNED`   — care-team assignment is operational / accountability
 *                   information that legitimately exists during pre-attendance
 *                   planning (a pre-assigned surgical team / admitting
 *                   clinician). This is the DELIBERATE divergence from the
 *                   ADR-135 clinical-record origination set — a
 *                   `CareTeamMembership` is NOT a clinical record, so C.6 sets
 *                   its OWN gate and ADR-135 is unchanged (ADR-142 D-K).
 *   - `ARRIVED` / `ACTIVE` — the attendance is in progress.
 *   - `COMPLETED` — retrospective membership correction is legitimate.
 *
 * Rejected for `CANCELLED` / `LEFT_WITHOUT_BEING_SEEN` / `ABANDONED` (void — no
 * care) and `TRANSFERRED` (the receiving facility builds its own care team on
 * its own encounter — ADR-130).
 *
 * ONLY origination (`AddCareTeamMember`) is gated: `EndCareTeamMembership` and
 * `ChangeProviderOfRecord` operate on the loaded membership(s) and make no
 * `EncounterApi` call (the C.2 / C.3 / C.4 post-origination principle; ADR-142
 * D-K).
 */
final class EncounterNotEligibleForCareTeam extends DomainException
{
    public static function status(string $encounterId, string $status): self
    {
        return new self(sprintf(
            'Encounter [%s] is %s — a care-team member cannot be added. '.
            'Assignment requires PLANNED, ARRIVED, ACTIVE or COMPLETED.',
            $encounterId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'encounter_not_eligible_for_care_team';
    }
}
