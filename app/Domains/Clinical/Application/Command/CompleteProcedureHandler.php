<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalProcedureRepository;
use App\Domains\Clinical\Domain\Service\ClinicalProcedureLifecycle;

/**
 * Orchestrates `CompleteProcedure` (IC §5; ADR-146 D-F).
 *
 * Flow: load (RLS-scoped) → `ClinicalProcedureLifecycle` validates
 * `IN_PROGRESS → COMPLETED` (else `IllegalProcedureTransition` 409) →
 * aggregate transition (`status → COMPLETED`, `completed_at` set once,
 * `outcome` / `outcome_detail` / `complications` optionally set; records
 * `ProcedureCompleted` carrying the coded `outcome`) → versioned save. The
 * `procedures.completed` audit row is written by the bus `WriteAuditRow` step.
 */
final class CompleteProcedureHandler
{
    public function __construct(
        private readonly ClinicalProcedureRepository $procedures,
        private readonly ClinicalProcedureLifecycle $lifecycle,
    ) {}

    public function __invoke(CompleteProcedure $command): void
    {
        $procedure = $this->procedures->getById($command->procedureId);

        $this->lifecycle->assertCanComplete($procedure->status);

        $procedure->complete($command->outcome, $command->outcomeDetail, $command->complications);

        $this->procedures->save($procedure);
    }
}
