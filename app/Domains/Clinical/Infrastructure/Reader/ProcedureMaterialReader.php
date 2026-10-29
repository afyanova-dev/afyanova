<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Reader;

use Illuminate\Support\Facades\DB;

final class ProcedureMaterialReader
{
    /**
     * @return list<array{materialRef: string, quantity: string, unit: string|null, batchRef: string|null, recordedAt: string}>
     */
    public function forProcedure(string $tenantId, string $facilityId, string $procedureId): array
    {
        return array_values(DB::table('clin_procedure_materials')
            ->join('clin_procedures', 'clin_procedures.id', '=', 'clin_procedure_materials.procedure_id')
            ->where('clin_procedure_materials.tenant_id', $tenantId)
            ->where('clin_procedures.tenant_id', $tenantId)
            ->where('clin_procedures.facility_id', $facilityId)
            ->where('clin_procedure_materials.procedure_id', $procedureId)
            ->orderBy('clin_procedure_materials.recorded_at')
            ->orderBy('clin_procedure_materials.id')
            ->get([
                'clin_procedure_materials.material_ref',
                'clin_procedure_materials.quantity',
                'clin_procedure_materials.unit',
                'clin_procedure_materials.batch_ref',
                'clin_procedure_materials.recorded_at',
            ])
            ->map(static fn (object $material): array => [
                'materialRef' => (string) $material->material_ref,
                'quantity' => (string) $material->quantity,
                'unit' => $material->unit === null ? null : (string) $material->unit,
                'batchRef' => $material->batch_ref === null ? null : (string) $material->batch_ref,
                'recordedAt' => (string) $material->recorded_at,
            ])
            ->values()
            ->all());
    }
}
