<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next tenant-scoped, human-readable prescription number
 * (ADR-115; ADR-181 D-C) — `RX-%08d`, tenant-wide unique.
 *
 * The raw counter SQL lives here in Infrastructure/, never in Domain/ or
 * Application/ (IC §9; the `EncounterNumberSequence` / `PatientNumberSequence`
 * precedent). Must run inside the `IngestPrescription` command transaction
 * (Tenancy::run) so `app.tenant_id` is set and the counter row's RLS predicate
 * matches. The UPDATE takes a row lock; concurrent allocations for one tenant
 * serialize on that row (IC §9.1). Written only by this class; no other module
 * reads or writes `rx_prescription_number_counters` (IC §2.1).
 */
final class PrescriptionNumberSequence
{
    private const PREFIX = 'RX-';

    public function next(string $tenantId): string
    {
        DB::table('rx_prescription_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update rx_prescription_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('rx_prescription_number_counters row missing for tenant after upsert (ADR-115).');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
