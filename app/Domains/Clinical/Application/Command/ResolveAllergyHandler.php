<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\AllergyRepository;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `ResolveAllergy` (IC §5; ADR-141 D-K / D-D).
 *
 * Flow: load the allergy (RLS-scoped; `NotFound` if missing / cross-tenant) ->
 * `Allergy::resolve()` guards `ACTIVE` (`IllegalAllergyStatusTransition` / 409
 * on a non-`ACTIVE` allergy — not idempotent, no unresolve), sets
 * `clinical_status` and `resolved_at` once, records `AllergyResolved` ->
 * versioned save (`ConcurrencyConflict` / 409 on a stale in-memory version).
 * NO history row (ADR-141 D-J — the ADR-140 D-P precedent). The
 * `clinical.allergies.resolved` audit row (with the backdate `reason`, when
 * present) is written by the bus `WriteAuditRow` step.
 *
 * NO `EncounterApi` / `PatientApi` call — resolution is not encounter-state-
 * gated (the C.2 / C.3 / C.4 post-origination principle). `AllergyRepository`
 * is injected both because the handler needs it and because `AuthorizeCommand`
 * (IC §5.1) reflects it to load the subject allergy for the per-subject
 * `resolve` policy check.
 */
final class ResolveAllergyHandler
{
    public function __construct(
        private readonly AllergyRepository $allergies,
    ) {}

    public function __invoke(ResolveAllergy $command): void
    {
        $allergy = $this->allergies->getById($command->allergyId); // RLS-scoped; NotFound if missing / cross-tenant

        $now = CarbonImmutable::now();
        $resolvedAt = $command->resolvedAt !== null ? CarbonImmutable::parse($command->resolvedAt) : $now;

        $allergy->resolve($resolvedAt); // ACTIVE-only; IllegalAllergyStatusTransition (409) otherwise

        $this->allergies->save($allergy);
    }
}
