<?php

declare(strict_types=1);

namespace App\Domains\Patient\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next tenant-scoped, human-readable patient number (ADR-115).
 *
 * The raw counter SQL lives here in Infrastructure/, never in Domain/ or
 * Application/ — IC §9 confines the DB facade / query builder to
 * Infrastructure, and the arch rule "a domain layer never touches the DB
 * facade or a query builder" enforces it against App\Domains\*\Domain and
 * App\Domains\*\Application.
 *
 * Must run inside the RegisterPatient command transaction (Tenancy::run —
 * IC §11.3), so `app.tenant_id` is already set and the counter row's RLS
 * predicate (Rls::enable, tenant-only shape — ADR-115) matches. Written only
 * by this class; no other module reads or writes pat_patient_number_counters.
 */
final class PatientNumberSequence
{
    private const PREFIX = 'PAT-';

    public function next(string $tenantId): string
    {
        DB::table('pat_patient_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update pat_patient_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('pat_patient_number_counters row missing for tenant after upsert (ADR-115).');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
