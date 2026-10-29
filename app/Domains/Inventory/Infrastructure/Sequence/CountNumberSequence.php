<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next tenant-scoped, human-readable stock-count number
 * (Phase 8) — `CNT-%08d`, the `TransferNumberSequence` shape.
 *
 * Must run inside the command transaction (Tenancy::run — IC §11.3). Written
 * only by this class; no other module reads or writes
 * `inv_count_number_counters` (IC §2.1).
 */
final class CountNumberSequence
{
    private const PREFIX = 'CNT-';

    public function next(string $tenantId): string
    {
        DB::table('inv_count_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update inv_count_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('inv_count_number_counters row missing for tenant after upsert (Phase 8).');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
