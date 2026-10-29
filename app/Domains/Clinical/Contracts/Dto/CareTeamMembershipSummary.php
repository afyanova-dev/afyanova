<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one care-team membership
 * (12-clinical-domain.md §4.1, §162; ADR-142 D-Q). Returned by the
 * `GetCareTeamMembership` query and, as a `list<CareTeamMembershipSummary>`, by
 * `GetEncounterCareTeam` — both via `CareTeamMembershipReader`.
 *
 * NOT an event payload (events carry no `end_reason` — ADR-142 D-R). NOT
 * `#[TypeScript]` — there is no PHASE 6 frontend consumer (ADR-142 D-Q);
 * `resources/js/shared/types/generated.ts` is unchanged.
 *
 * Identifiers plus the membership's own stored fields ONLY — no resolved
 * patient name / demographics, no encounter detail. `activeFrom` / `activeTo` /
 * `addedAt` are ISO-8601 strings; `activeTo` is null while the membership is
 * ACTIVE. `isProviderOfRecord` is true only for the encounter's single active
 * provider-of-record. There is NO `providerOfRecordReason` — C.6 captures none
 * (ADR-142 D-H / reconciliation R-1). `endReason` is returned to an authorized
 * reader (the `#[ProtectedQuery]` gate plus the originating-facility refinement
 * IS the authorization — the `ProblemSummary` / `AllergySummary` precedent).
 */
final readonly class CareTeamMembershipSummary
{
    public function __construct(
        public string $membershipId,
        public string $encounterId,
        public string $patientId,
        public string $facilityId,
        public string $staffUserId,
        public string $teamRole,
        public bool $isProviderOfRecord,
        public string $activeFrom,
        public ?string $activeTo,
        public string $addedByUserId,
        public ?string $removedByUserId,
        public ?string $endReason,
        public string $addedAt,
        public int $version,
    ) {}
}
