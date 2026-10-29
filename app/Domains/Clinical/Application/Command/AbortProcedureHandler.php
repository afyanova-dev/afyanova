<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalProcedureRepository;
use App\Domains\Clinical\Domain\Service\ClinicalProcedureLifecycle;

/**
 * Orchestrates `AbortProcedure` (IC §5; ADR-146 D-F / D-J).
 *
 * Flow: load (RLS-scoped) → `ClinicalProcedureLifecycle` validates
 * `IN_PROGRESS → ABORTED` (else `IllegalProcedureTransition` 409) → aggregate
 * transition (`status → ABORTED`, mandatory `status_reason`; records
 * `ProcedureAborted`) → versioned save. The `procedures.aborted` audit row
 * (`reason = null` — the mandatory rationale lives on the row) is written by
 * the bus `WriteAuditRow` step.
 */
final class AbortProcedureHandler
{
    public function __construct(
        private readonly ClinicalProcedureRepository $procedures,
        private readonly ClinicalProcedureLifecycle $lifecycle,
    ) {}

    public function __invoke(AbortProcedure $command): void
    {
        $procedure = $this->procedures->getById($command->procedureId);

        $this->lifecycle->assertCanAbort($procedure->status);

        $procedure->abort($command->reason);

        $this->procedures->save($procedure);
    }
}
