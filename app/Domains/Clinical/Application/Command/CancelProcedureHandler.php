<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalProcedureRepository;
use App\Domains\Clinical\Domain\Service\ClinicalProcedureLifecycle;

/**
 * Orchestrates `CancelProcedure` (IC §5; ADR-146 D-F).
 *
 * Flow: load (RLS-scoped) → `ClinicalProcedureLifecycle` validates
 * `{REQUESTED, AUTHORIZED, READY} → CANCELLED` (else
 * `IllegalProcedureTransition` 409) → aggregate transition (`status →
 * CANCELLED`, optional `status_reason`; records `ProcedureCancelled`) →
 * versioned save. The `procedures.cancelled` audit row (`reason = null` — the
 * rationale lives on the row, ADR-146 D-J) is written by the bus
 * `WriteAuditRow` step.
 */
final class CancelProcedureHandler
{
    public function __construct(
        private readonly ClinicalProcedureRepository $procedures,
        private readonly ClinicalProcedureLifecycle $lifecycle,
    ) {}

    public function __invoke(CancelProcedure $command): void
    {
        $procedure = $this->procedures->getById($command->procedureId);

        $this->lifecycle->assertCanCancel($procedure->status);

        $procedure->cancel($command->reason);

        $this->procedures->save($procedure);
    }
}
