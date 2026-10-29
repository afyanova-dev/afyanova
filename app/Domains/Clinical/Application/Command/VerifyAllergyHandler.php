<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\AllergyRepository;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `VerifyAllergy` (IC §5; ADR-141 D-K / D-D).
 *
 * Flow: load the allergy (RLS-scoped; `NotFound` if missing / cross-tenant) ->
 * `Allergy::verify()` guards the legal transition, captures the COMPLETE
 * pre-change state as an append-only `clin_allergy_history` row
 * (`change_kind = VERIFICATION`), sets `verification_status` and — for
 * `REFUTED` — atomically sets `clinical_status = INACTIVE` (the C.5
 * biconditional), records `AllergyVerified` -> versioned save
 * (`ConcurrencyConflict` / 409 on a stale in-memory version). The
 * `clinical.allergies.verified` audit row (`metadata = []`, `reason = null` —
 * the `REFUTED` rationale is clinical data on the history row, ADR-141 D-R) is
 * written by the bus `WriteAuditRow` step.
 *
 * The change `occurred_at` / `recorded_at` are server `now()`, NOT
 * caller-supplied (ADR-141 D-H). NO `EncounterApi` / `PatientApi` call —
 * verification is not encounter-state-gated (the C.2 / C.3 / C.4
 * post-origination principle). `AllergyRepository` is injected both because the
 * handler needs it and because `AuthorizeCommand` (IC §5.1) reflects it to load
 * the subject allergy for the per-subject `verify` policy check.
 */
final class VerifyAllergyHandler
{
    public function __construct(
        private readonly AllergyRepository $allergies,
    ) {}

    public function __invoke(VerifyAllergy $command): void
    {
        $allergy = $this->allergies->getById($command->allergyId); // RLS-scoped; NotFound if missing / cross-tenant

        $now = CarbonImmutable::now();

        $allergy->verify(
            verificationStatus: $command->verificationStatus,
            reason: $command->refutationReason(),
            authorUserId: $command->actorId,
            changeOccurredAt: $now,
            changeRecordedAt: $now,
        );

        $this->allergies->save($allergy);
    }
}
