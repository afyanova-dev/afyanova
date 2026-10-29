<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\AllergyRepository;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `UpdateAllergy` (IC §5; ADR-141 D-K / D-J).
 *
 * Flow: load the allergy (RLS-scoped; `NotFound` if missing / cross-tenant) ->
 * `Allergy::revise()` captures the COMPLETE pre-change state as an append-only
 * `clin_allergy_history` row (`change_kind = CONTENT`) and then applies the new
 * `substance` / `category` / `reaction_description` / `severity` / `onset_date`
 * (records `AllergyUpdated`) -> versioned save (`ConcurrencyConflict` / 409 on
 * a stale in-memory version — no history row, no partial state). The
 * `clinical.allergies.updated` audit row (`metadata = []`, `reason = null` —
 * the `CORRECTION` rationale is clinical data on the history row, ADR-141 D-R)
 * is written by the bus `WriteAuditRow` step.
 *
 * STATUS-AGNOSTIC (ADR-141 D-K) — no status precondition; `Allergy::revise()`
 * never touches `clinical_status` / `verification_status` / `resolved_at`. The
 * change `occurred_at` / `recorded_at` are server `now()`, NOT caller-supplied
 * (the `UpdateProblemHandler` precedent; ADR-141 D-H). NO `EncounterApi` /
 * `PatientApi` call — a revision / correction is not encounter-state-gated (the
 * C.2 / C.3 / C.4 post-origination principle). `AllergyRepository` is injected
 * both because the handler needs it and because `AuthorizeCommand` (IC §5.1)
 * reflects it to load the subject allergy for the per-subject `update` policy
 * check.
 */
final class UpdateAllergyHandler
{
    public function __construct(
        private readonly AllergyRepository $allergies,
    ) {}

    public function __invoke(UpdateAllergy $command): void
    {
        $allergy = $this->allergies->getById($command->allergyId); // RLS-scoped; NotFound if missing / cross-tenant

        $now = CarbonImmutable::now();
        $onsetDate = $command->onsetDate !== null ? CarbonImmutable::parse($command->onsetDate) : null;

        $allergy->revise(
            changeType: $command->changeType,
            substance: $command->substance,
            category: $command->category,
            reactionDescription: $command->reactionDescription,
            severity: $command->severity,
            onsetDate: $onsetDate,
            reason: $command->correctionReason(),
            authorUserId: $command->actorId,
            changeOccurredAt: $now,
            changeRecordedAt: $now,
        );

        $this->allergies->save($allergy);
    }
}
