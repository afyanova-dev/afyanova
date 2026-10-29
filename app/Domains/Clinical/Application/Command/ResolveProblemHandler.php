<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ProblemRepository;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `ResolveProblem` (IC §5; ADR-140 D-E).
 *
 * Flow: load the problem (RLS-scoped; `NotFound` if missing / cross-tenant) ->
 * `Problem::resolve()` guards `ACTIVE` (`IllegalProblemStatusTransition` / 409
 * on a `RESOLVED` problem — not idempotent, no reactivation), sets `status` and
 * `resolved_at` once, records `ProblemResolved` -> versioned save
 * (`ConcurrencyConflict` / 409 on a stale in-memory version). NO history row
 * (ADR-140 D-P). The `clinical.problems.resolved` audit row (with the backdate
 * `reason`, when present) is written by the bus `WriteAuditRow` step.
 *
 * NO `EncounterApi` / `PatientApi` call — resolution is not encounter-state-
 * gated (the C.2 / C.3 post-origination principle; ADR-140 D-O).
 * `ProblemRepository` is injected both because the handler needs it and because
 * `AuthorizeCommand` (IC §5.1) reflects it to load the subject problem for the
 * per-subject `resolve` policy check.
 */
final class ResolveProblemHandler
{
    public function __construct(
        private readonly ProblemRepository $problems,
    ) {}

    public function __invoke(ResolveProblem $command): void
    {
        $problem = $this->problems->getById($command->problemId); // RLS-scoped; NotFound if missing / cross-tenant

        $now = CarbonImmutable::now();
        $resolvedAt = $command->resolvedAt !== null ? CarbonImmutable::parse($command->resolvedAt) : $now;

        $problem->resolve($resolvedAt); // ACTIVE-only; IllegalProblemStatusTransition (409) otherwise

        $this->problems->save($problem);
    }
}
