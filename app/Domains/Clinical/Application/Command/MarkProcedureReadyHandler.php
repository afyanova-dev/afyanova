<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalProcedureRepository;
use App\Domains\Clinical\Domain\ProcedureDefinitionReader;
use App\Domains\Clinical\Domain\Service\ClinicalProcedureLifecycle;

/**
 * Orchestrates `MarkProcedureReady` (IC §5; ADR-146 D-F).
 *
 * Flow: load the procedure (RLS-scoped) → `ProcedureDefinitionReader::summary()`
 * (intra-domain, READ-ONLY) resolves `requires_readiness_check` →
 * `ClinicalProcedureLifecycle` validates `AUTHORIZED → READY` AND the flag
 * (else `IllegalProcedureTransition` 409 — either the wrong status or the
 * definition does not require readiness) → aggregate transition (`status →
 * READY`, `ready_at` set once; records `ProcedureReady`) → versioned save.
 * The `procedures.ready` audit row is written by the bus `WriteAuditRow` step.
 */
final class MarkProcedureReadyHandler
{
    public function __construct(
        private readonly ClinicalProcedureRepository $procedures,
        private readonly ProcedureDefinitionReader $definitions,
        private readonly ClinicalProcedureLifecycle $lifecycle,
    ) {}

    public function __invoke(MarkProcedureReady $command): void
    {
        $procedure = $this->procedures->getById($command->procedureId);
        $definition = $this->definitions->summary((string) $procedure->definition_id);

        $this->lifecycle->assertCanMarkReady($procedure->status, $definition->requiresReadinessCheck);

        $procedure->markReady();

        $this->procedures->save($procedure);
    }
}
