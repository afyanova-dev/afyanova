<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next tenant-scoped, human-readable laboratory order number
 * (ADR-157 D-D) — `LAB-%08d`, tenant-wide unique (the `CaseNumberSequence` /
 * `EncounterNumberSequence` precedent — same class shape here).
 *
 * Must run inside the CreateLabOrder command transaction (Tenancy::run —
 * IC §11.3). Written only by this class; no other module reads or writes
 * `lab_order_number_counters` (IC §2.1).
 */
final class OrderNumberSequence
{
    private const PREFIX = 'LAB-';

    public function next(string $tenantId): string
    {
        DB::table('lab_order_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update lab_order_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('lab_order_number_counters row missing for tenant after upsert (ADR-157).');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
