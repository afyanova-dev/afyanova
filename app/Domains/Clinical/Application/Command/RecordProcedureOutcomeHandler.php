<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalProcedureRepository;
use App\Domains\Clinical\Domain\Service\ClinicalProcedureLifecycle;

/**
 * Orchestrates `RecordProcedureOutcome` (IC §5; ADR-146 D-I / S3).
 *
 * Flow: load (RLS-scoped) → `ClinicalProcedureLifecycle` validates the
 * procedure is `IN_PROGRESS` (else `IllegalProcedureTransition` 409 — post-
 * completion use `AmendProcedure` instead) → aggregate sets / revises
 * `outcome` / `outcome_detail` (no domain event) → versioned save. The
 * `procedures.outcome_recorded` audit row is written by the bus
 * `WriteAuditRow` step.
 */
final class RecordProcedureOutcomeHandler
{
    public function __construct(
        private readonly ClinicalProcedureRepository $procedures,
        private readonly ClinicalProcedureLifecycle $lifecycle,
    ) {}

    public function __invoke(RecordProcedureOutcome $command): void
    {
        $procedure = $this->procedures->getById($command->procedureId);

        $this->lifecycle->assertCanRecordOutcome($procedure->status);

        $procedure->recordOutcome($command->outcome, $command->outcomeDetail);

        $this->procedures->save($procedure);
    }
}
