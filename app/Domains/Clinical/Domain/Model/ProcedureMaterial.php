<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Model;

use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;

/**
 * One append-only "materials actually consumed" row against a
 * {@see ClinicalProcedure} (13-clinical-procedures-domain.md §50–§53; ADR-137,
 * ADR-146 D-N).
 *
 * Its OWN small tenant-scoped aggregate. Recordable while the procedure is
 * `IN_PROGRESS` or `COMPLETED` — "during or after" (13 §52). Clinical
 * Procedures records the FACT of consumption; Inventory (PHASE 9) owns the
 * stock movement — NO stock balances here. The row is written ONCE and NEVER
 * updated or deleted (`Rls::enableAppendOnly`); a mis-keyed line is corrected
 * via a compensating `AmendProcedure` note (a void / adjust flow is deferred).
 * `version` stays 0 for its whole life (IC §9.1 / §10 table-shape only).
 *
 * @domain      Clinical
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    none on this row — scoped via the parent `procedure_id`
 *
 * @property CarbonImmutable $recorded_at
 */
final class ProcedureMaterial extends AggregateRoot
{
    protected $table = 'clin_procedure_materials';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'procedure_id',
        'material_ref', 'material_code_system', 'quantity', 'unit', 'batch_ref',
        'recorded_by_user_id', 'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public static function record(
        string $id,
        string $tenantId,
        string $procedureId,
        string $materialRef,
        ?string $materialCodeSystem,
        float $quantity,
        ?string $unit,
        ?string $batchRef,
        string $recordedByUserId,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'procedure_id' => $procedureId,
            'material_ref' => $materialRef,
            'material_code_system' => $materialCodeSystem,
            'quantity' => $quantity,
            'unit' => $unit,
            'batch_ref' => $batchRef,
            'recorded_by_user_id' => $recordedByUserId,
            'recorded_at' => $recordedAt,
        ]);
    }
}
