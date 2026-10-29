<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next tenant-scoped, human-readable stock-movement number
 * (ADR-170 D-K) — `MOV-%08d`, tenant-wide unique (the Rad.1 `OrderNumberSequence`
 * / ADR-115 precedent, same class shape). §50's `MOV-2026-000123` year form is
 * illustrative.
 *
 * Must run inside the command transaction (Tenancy::run — IC §11.3). Written
 * only by this class; no other module reads or writes
 * `inv_movement_number_counters` (IC §2.1).
 */
final class MovementNumberSequence
{
    private const PREFIX = 'MOV-';

    public function next(string $tenantId): string
    {
        DB::table('inv_movement_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update inv_movement_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('inv_movement_number_counters row missing for tenant after upsert (ADR-170 D-K).');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
