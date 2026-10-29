<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalProcedureRepository;
use App\Domains\Clinical\Domain\Service\ClinicalProcedureLifecycle;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `RecordProcedureMaterials` (IC §5; ADR-146 D-N).
 *
 * Flow: load (RLS-scoped) → `ClinicalProcedureLifecycle` validates
 * `{IN_PROGRESS, COMPLETED}` (else `IllegalProcedureTransition` 409) →
 * aggregate appends one `clin_procedure_materials` row per line and records
 * ONE `ProcedureMaterialsConsumed` event carrying every line → versioned save
 * (the procedure `UPDATE` — dirtied by nothing else, but `save()` still runs —
 * then each material `INSERT`, then the event to the outbox, one transaction).
 * The `procedures.materials_recorded` audit row is written by the bus
 * `WriteAuditRow` step.
 */
final class RecordProcedureMaterialsHandler
{
    public function __construct(
        private readonly ClinicalProcedureRepository $procedures,
        private readonly ClinicalProcedureLifecycle $lifecycle,
    ) {}

    public function __invoke(RecordProcedureMaterials $command): void
    {
        $procedure = $this->procedures->getById($command->procedureId);

        $this->lifecycle->assertCanRecordMaterials($procedure->status);

        $materials = array_map(static fn (array $line): array => [
            'materialRef' => (string) $line['materialRef'],
            'materialCodeSystem' => isset($line['materialCodeSystem']) ? (string) $line['materialCodeSystem'] : null,
            'quantity' => (float) $line['quantity'],
            'unit' => isset($line['unit']) ? (string) $line['unit'] : null,
            'batchRef' => isset($line['batchRef']) ? (string) $line['batchRef'] : null,
        ], $command->materials);

        $procedure->recordMaterialsConsumed($materials, $command->actorId, CarbonImmutable::now());

        $this->procedures->save($procedure);
    }
}
