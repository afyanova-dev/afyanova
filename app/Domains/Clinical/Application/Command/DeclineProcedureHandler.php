<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalProcedureRepository;
use App\Domains\Clinical\Domain\Service\ClinicalProcedureLifecycle;

/**
 * Orchestrates `DeclineProcedure` (IC §5; ADR-146 D-F).
 *
 * Flow: load (RLS-scoped) → `ClinicalProcedureLifecycle` validates
 * `{AUTHORIZED, READY} → DECLINED` (else `IllegalProcedureTransition` 409) →
 * aggregate transition (`status → DECLINED`, optional `status_reason`;
 * records `ProcedureDeclined`) → versioned save. The `procedures.declined`
 * audit row (`reason = null`) is written by the bus `WriteAuditRow` step.
 */
final class DeclineProcedureHandler
{
    public function __construct(
        private readonly ClinicalProcedureRepository $procedures,
        private readonly ClinicalProcedureLifecycle $lifecycle,
    ) {}

    public function __invoke(DeclineProcedure $command): void
    {
        $procedure = $this->procedures->getById($command->procedureId);

        $this->lifecycle->assertCanDecline($procedure->status);

        $procedure->decline($command->reason);

        $this->procedures->save($procedure);
    }
}
