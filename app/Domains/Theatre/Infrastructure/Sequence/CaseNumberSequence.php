<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next tenant-scoped, human-readable surgical case number
 * (ADR-150 D-C) — `SURG-%08d`, tenant-wide unique (the ProcedureNumberSequence
 * / EncounterNumberSequence precedent — same class shape here).
 *
 * Must run inside the CreateSurgicalCase command transaction (Tenancy::run —
 * IC §11.3). Written only by this class; no other module reads or writes
 * thr_case_number_counters (IC §2.1).
 */
final class CaseNumberSequence
{
    private const PREFIX = 'SURG-';

    public function next(string $tenantId): string
    {
        DB::table('thr_case_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update thr_case_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('thr_case_number_counters row missing for tenant after upsert (ADR-150).');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
