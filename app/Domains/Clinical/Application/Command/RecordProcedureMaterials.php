<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record materials actually consumed during / after a procedure
 * (13-clinical-procedures-domain.md §50–§53; ADR-137, ADR-146 D-N). Callable
 * while the procedure is `IN_PROGRESS` or `COMPLETED` — "during OR at
 * completion" (13 §52). Emits ONE PHI-free `ProcedureMaterialsConsumed` event
 * carrying every line; Inventory (PHASE 9) reacts in its own increment — NO
 * stock balances are recorded here.
 *
 * `$materials` is a non-empty list of lines, each:
 *   `materialRef`         string  REQUIRED — open reference (no terminology engine)
 *   `materialCodeSystem`  ?string OPTIONAL
 *   `quantity`            float   REQUIRED, > 0
 *   `unit`                ?string OPTIONAL — open
 *   `batchRef`            ?string OPTIONAL (13 §53)
 *
 * Per-subject command (`subjectId() = $procedureId`): the bus loads the
 * procedure and calls `ProcedurePolicy::recordMaterials`
 * (`clinical.procedures.perform` at the procedure's own facility) before the
 * handler runs.
 */
final readonly class RecordProcedureMaterials implements Auditable, AuthorizesAgainst
{
    /**
     * @param  list<array{materialRef: string, materialCodeSystem?: string|null, quantity: float|int, unit?: string|null, batchRef?: string|null}>  $materials
     */
    public function __construct(
        public string $procedureId,
        public string $actorId,
        public array $materials,
    ) {
        if (! Str::isUuid($procedureId)) {
            throw new InvalidArgumentException('RecordProcedureMaterials::$procedureId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RecordProcedureMaterials::$actorId must be a UUID (IC §3).');
        }

        if ($materials === []) {
            throw new InvalidArgumentException('RecordProcedureMaterials::$materials must not be empty.');
        }

        foreach ($materials as $line) {
            if (trim($line['materialRef']) === '') {
                throw new InvalidArgumentException('RecordProcedureMaterials: every line requires a non-blank materialRef.');
            }

            if ((float) $line['quantity'] <= 0.0) {
                throw new InvalidArgumentException('RecordProcedureMaterials: every line requires a quantity > 0.');
            }
        }
    }

    public function policyAbility(): string
    {
        return 'recordMaterials';
    }

    public function subjectId(): string
    {
        return $this->procedureId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procedures.materials_recorded',
            actorId: $this->actorId,
            targetType: 'clinical_procedure',
            targetId: $this->procedureId,
        );
    }
}
