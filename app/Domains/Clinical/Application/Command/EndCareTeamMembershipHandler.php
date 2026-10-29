<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\CareTeamMembershipRepository;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `EndCareTeamMembership` (IC §5; ADR-142 D-I / D-J).
 *
 * Flow: load the membership (RLS-scoped; `NotFound` if missing / cross-tenant)
 * -> `CareTeamMembership::end()` guards `ACTIVE`
 * (`IllegalCareTeamMembershipTransition` / 409 on an already-ENDED membership —
 * not idempotent, no reactivation), sets `active_to` / `removed_by_user_id` /
 * `end_reason` (row only), clears `is_provider_of_record` if set, and records
 * `CareTeamMemberRemoved` (+ `ProviderOfRecordChanged` with new provider = null
 * when it was the provider-of-record) -> versioned save
 * (`ConcurrencyConflict` / 409 on a stale in-memory version) + drain events to
 * the outbox (one txn).
 *
 * NO `EncounterApi` / `PatientApi` call — ending a membership is not
 * encounter-state- or patient-state-gated (the post-origination principle;
 * ADR-142 D-K / D-L). `CareTeamMembershipRepository` is injected both because
 * the handler needs it and because `AuthorizeCommand` (IC §5.1) reflects it to
 * load the subject membership for the per-subject `end` policy check.
 */
final class EndCareTeamMembershipHandler
{
    public function __construct(
        private readonly CareTeamMembershipRepository $memberships,
    ) {}

    public function __invoke(EndCareTeamMembership $command): void
    {
        $membership = $this->memberships->getById($command->careTeamMembershipId); // RLS-scoped; NotFound if missing / cross-tenant

        $membership->end(
            removedByUserId: $command->actorId,
            endReason: $command->endReason(),
            endedAt: CarbonImmutable::now(),
        );

        $this->memberships->save($membership);
    }
}
