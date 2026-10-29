<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalProcedureRepository;
use App\Domains\Clinical\Domain\Service\ClinicalProcedureLifecycle;

/**
 * Orchestrates `AuthorizeProcedure` (IC §5; ADR-146 D-F).
 *
 * Flow: load (RLS-scoped) → `ClinicalProcedureLifecycle` validates
 * `REQUESTED → AUTHORIZED` (else `IllegalProcedureTransition` 409) → aggregate
 * transition (`status → AUTHORIZED`, `authorized_by_user_id` + `authorized_at`
 * set once; records `ProcedureAuthorized`) → versioned save
 * (`ConcurrencyConflict` 409 on a stale version). The `procedures.authorized`
 * audit row is written by the bus `WriteAuditRow` step (`metadata = []`).
 */
final class AuthorizeProcedureHandler
{
    public function __construct(
        private readonly ClinicalProcedureRepository $procedures,
        private readonly ClinicalProcedureLifecycle $lifecycle,
    ) {}

    public function __invoke(AuthorizeProcedure $command): void
    {
        $procedure = $this->procedures->getById($command->procedureId);

        $this->lifecycle->assertCanAuthorize($procedure->status);

        $procedure->authorize($command->actorId);

        $this->procedures->save($procedure);
    }
}
