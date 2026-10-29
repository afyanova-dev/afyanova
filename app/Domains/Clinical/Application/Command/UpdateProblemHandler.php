<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ProblemRepository;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `UpdateProblem` (IC §5; ADR-140 D-J).
 *
 * Flow: load the problem (RLS-scoped; `NotFound` if missing / cross-tenant) ->
 * `Problem::revise()` captures the COMPLETE pre-change state as an append-only
 * `clin_problem_history` row and then applies the new `description` / `code` /
 * `code_system` / `onset_date` (records `ProblemUpdated`) -> versioned save
 * (`ConcurrencyConflict` / 409 on a stale in-memory version — no history row,
 * no partial state). The `clinical.problems.updated` audit row is written by
 * the bus `WriteAuditRow` step (`metadata = []`, `reason = null` — the
 * `CORRECTION` rationale is clinical data on the history row, ADR-140 D-N).
 *
 * STATUS-AGNOSTIC (ADR-140 clarification lock, Decision 1) — no status
 * precondition; `Problem::revise()` never touches `status` / `resolved_at`.
 * NO `EncounterApi` / `PatientApi` call — a revision / correction is not
 * encounter-state-gated (the C.2 / C.3 post-origination principle; ADR-140
 * D-O). `ProblemRepository` is injected both because the handler needs it and
 * because `AuthorizeCommand` (IC §5.1) reflects it to load the subject problem
 * for the per-subject `update` policy check.
 */
final class UpdateProblemHandler
{
    public function __construct(
        private readonly ProblemRepository $problems,
    ) {}

    public function __invoke(UpdateProblem $command): void
    {
        $problem = $this->problems->getById($command->problemId); // RLS-scoped; NotFound if missing / cross-tenant

        $now = CarbonImmutable::now();
        $onsetDate = $command->onsetDate !== null ? CarbonImmutable::parse($command->onsetDate) : null;

        $problem->revise(
            changeType: $command->changeType,
            coding: $command->coding,
            description: $command->description,
            onsetDate: $onsetDate,
            reason: $command->correctionReason(),
            authorUserId: $command->actorId,
            changeOccurredAt: $now,
            changeRecordedAt: $now,
        );

        $this->problems->save($problem);
    }
}
