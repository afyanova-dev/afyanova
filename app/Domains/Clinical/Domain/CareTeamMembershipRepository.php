<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Domain\Model\CareTeamMembership;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the CareTeamMembership aggregate (IC §9; ADR-142).
 * Implementation: `Infrastructure/Repository/EloquentCareTeamMembershipRepository`,
 * bound in `ClinicalServiceProvider`. Its FQCN sits directly under `Domain/`
 * (not `Domain/Repository/`) so `App\Shared\Bus\Middleware\AuthorizeCommand`
 * (IC §5.1) can derive `App\Domains\Clinical\Domain\Model\CareTeamMembership`
 * from it for the class-level `record` (AddCareTeamMember) /
 * `changeProviderOfRecord` and per-subject `end` (EndCareTeamMembership) policy
 * checks.
 *
 * `save()` (inherited) persists the versioned `clin_care_team_memberships`
 * change and drains the released domain events to the outbox — all in the bus
 * transaction. `changeProviderOfRecord()` persists BOTH instances of an atomic
 * provider-of-record hand-off (ADR-142 D-C — the `EloquentDiagnosisRepository::
 * saveCorrection` precedent).
 */
interface CareTeamMembershipRepository extends Repository
{
    public function getById(string $id): CareTeamMembership;

    public function getByIdForUpdate(string $id): CareTeamMembership;

    /**
     * The encounter's ACTIVE care-team memberships (`active_to IS NULL`),
     * RLS-scoped — the write-side load `ChangeProviderOfRecordHandler` needs to
     * resolve the incoming member, the current provider-of-record and the
     * shared originating `facility_id` (ADR-142 D-N). Ordered by `active_from`
     * then `id`. An encounter with no in-tenant memberships yields `[]`.
     *
     * @return list<CareTeamMembership>
     */
    public function activeForEncounter(string $encounterId): array;

    /**
     * Persist an atomic provider-of-record hand-off (ADR-142 D-C / D-J). The
     * OUTGOING membership (its flag already cleared in memory) is written FIRST
     * so it vacates the `clin_one_provider_of_record_per_encounter` partial
     * unique index before the INCOMING membership (its flag already set, and
     * carrying the single `ProviderOfRecordChanged` event) is written. Both
     * writes and the event drain happen in the bus transaction. `$outgoing` is
     * null for a first assignment.
     */
    public function changeProviderOfRecord(?CareTeamMembership $outgoing, CareTeamMembership $incoming): void;
}
