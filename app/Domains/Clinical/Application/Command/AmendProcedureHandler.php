<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalProcedureRepository;
use App\Domains\Clinical\Domain\Service\ClinicalProcedureLifecycle;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `AmendProcedure` (IC §5; ADR-146 D-M).
 *
 * Flow: load (RLS-scoped) → `ClinicalProcedureLifecycle` validates
 * `{COMPLETED, ABORTED}` (else `IllegalProcedureTransition` 409) → aggregate
 * applies the supplied field changes + appends one `clin_procedure_amendments`
 * row (no domain event — no PHASE-6 consumer) → versioned save (the procedure
 * `UPDATE`, then the amendment `INSERT`, one transaction). The
 * `procedures.amended` audit row (`reason = null` — the correction rationale
 * lives on the amendment row) is written by the bus `WriteAuditRow` step.
 */
final class AmendProcedureHandler
{
    public function __construct(
        private readonly ClinicalProcedureRepository $procedures,
        private readonly ClinicalProcedureLifecycle $lifecycle,
    ) {}

    public function __invoke(AmendProcedure $command): void
    {
        $procedure = $this->procedures->getById($command->procedureId);

        $this->lifecycle->assertCanAmend($procedure->status);

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;

        $procedure->amend(
            amendmentType: $command->amendmentType,
            body: $command->body,
            reason: $command->reason,
            fields: $command->changedFields(),
            authorUserId: $command->actorId,
            occurredAt: $occurredAt,
            recordedAt: $now,
        );

        $this->procedures->save($procedure);
    }
}
