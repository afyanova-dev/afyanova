<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next tenant-scoped, human-readable procedure number (ADR-137) —
 * `PROC-%08d`, tenant-wide unique (NOT the year-segmented `13 §10` example —
 * ADR-137 overrides it).
 *
 * The raw counter SQL lives here in Infrastructure/, never in Domain/ or
 * Application/ (IC §9; the `EncounterNumberSequence` / `PatientNumberSequence`
 * precedent — same class shape here).
 *
 * Must run inside the `RequestProcedure` command transaction (Tenancy::run —
 * IC §11.3), so `app.tenant_id` is set and the counter row's RLS predicate
 * (`Rls::enable`, tenant-only shape) matches. The `UPDATE` takes a row lock;
 * concurrent allocations for one tenant serialize on that row (IC §9.1).
 * Written only by this class; no other module reads or writes
 * `clin_procedure_number_counters` (IC §2.1).
 */
final class ProcedureNumberSequence
{
    private const PREFIX = 'PROC-';

    public function next(string $tenantId): string
    {
        DB::table('clin_procedure_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update clin_procedure_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('clin_procedure_number_counters row missing for tenant after upsert (ADR-137).');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
