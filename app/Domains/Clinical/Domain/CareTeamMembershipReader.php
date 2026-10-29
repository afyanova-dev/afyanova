<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Contracts\Dto\CareTeamMembershipSummary;

/**
 * Read-side port for CareTeamMembership (IC §6.2 / §6.3; ADR-142 D-Q).
 * Implementation: `Infrastructure/Reader/EloquentCareTeamMembershipReader`.
 * Returns DTOs only — never an Eloquent model (IC §9).
 *
 * Exactly two read capabilities (ADR-142 D-P) — one internal interface:
 *
 *   - `summary`       backs the `GetCareTeamMembership` protected query (by id);
 *   - `forEncounter`  backs the `GetEncounterCareTeam` protected query (one
 *                     encounter's care team; `$includeEnded` false by default;
 *                     deterministic ordering; NO pagination;
 *                     originating-facility-scoped via `$inScopeFacilityIds`).
 *
 * C.6 publishes NO `ClinicalApi` care-team method, NO `clin_rm_*` projection, NO
 * patient-level reader and NO provider-of-record-history reader (ADR-142 D-P /
 * D-Q — all PHASE 16 / a later decision).
 */
interface CareTeamMembershipReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if the membership does not exist in
     * the current tenant scope (RLS). No facility-scope check here — that is
     * `GetCareTeamMembershipHandler`'s per-caller refinement against the
     * membership's ORIGINATING facility (ADR-142 D-M / D-N; the E.12 R-1
     * pattern).
     */
    public function summary(string $membershipId): CareTeamMembershipSummary;

    /**
     * One encounter's care team, filtered to memberships whose ORIGINATING
     * `facility_id` is in `$inScopeFacilityIds` (ADR-142 D-M —
     * originating-facility-only visibility; NOT the ADR-141 D-M tenant-wide-read
     * exception). `$includeEnded` false (default) returns only ACTIVE
     * memberships (`active_to IS NULL`); true also returns ENDED memberships
     * (the "everyone who was ever on this team" read — there is no separate
     * history query). Deterministic ordering (`is_provider_of_record` DESC,
     * then `team_role`, then `active_from`, then `id`); NO pagination (a care
     * team is small — ADR-142 D-P). An empty `$inScopeFacilityIds` yields an
     * empty list.
     *
     * @param  list<string>  $inScopeFacilityIds
     * @return list<CareTeamMembershipSummary>
     */
    public function forEncounter(string $encounterId, array $inScopeFacilityIds, bool $includeEnded = false): array;
}
