<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalProcedureRepository;
use App\Domains\Clinical\Domain\ProcedureDefinitionReader;
use App\Domains\Clinical\Domain\Service\ClinicalProcedureLifecycle;

/**
 * Orchestrates `StartProcedure` (IC §5; ADR-146 D-F / D-H).
 *
 * Flow: load the procedure (RLS-scoped) → `ProcedureDefinitionReader::summary()`
 * resolves `requires_readiness_check` / `requires_site` / `requires_laterality`
 * → `ClinicalProcedureLifecycle` validates `{AUTHORIZED, READY} → IN_PROGRESS`
 * (else `IllegalProcedureTransition` 409) → aggregate transition (`status →
 * IN_PROGRESS`, `performed_by_user_id` + `started_at` set once, `site` /
 * `laterality` validated against the flags; records `ProcedureStarted`) →
 * versioned save. The `procedures.started` audit row is written by the bus
 * `WriteAuditRow` step.
 */
final class StartProcedureHandler
{
    public function __construct(
        private readonly ClinicalProcedureRepository $procedures,
        private readonly ProcedureDefinitionReader $definitions,
        private readonly ClinicalProcedureLifecycle $lifecycle,
    ) {}

    public function __invoke(StartProcedure $command): void
    {
        $procedure = $this->procedures->getById($command->procedureId);
        $definition = $this->definitions->summary((string) $procedure->definition_id);

        $this->lifecycle->assertCanStart($procedure->status, $definition->requiresReadinessCheck);

        $procedure->start(
            performedByUserId: $command->actorId,
            requiresSite: $definition->requiresSite,
            requiresLaterality: $definition->requiresLaterality,
            site: $command->site,
            laterality: $command->laterality,
        );

        $this->procedures->save($procedure);
    }
}
