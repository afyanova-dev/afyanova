<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next tenant-scoped, human-readable imaging order number
 * (ADR-165 D-E) — `RAD-%08d`, tenant-wide unique (the Lab.1 `OrderNumberSequence`
 * / `CaseNumberSequence` precedent — same class shape here).
 *
 * Must run inside the CreateImagingOrder command transaction (Tenancy::run —
 * IC §11.3). Written only by this class; no other module reads or writes
 * `rad_order_number_counters` (IC §2.1).
 */
final class OrderNumberSequence
{
    private const PREFIX = 'RAD-';

    public function next(string $tenantId): string
    {
        DB::table('rad_order_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update rad_order_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('rad_order_number_counters row missing for tenant after upsert (ADR-165).');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
