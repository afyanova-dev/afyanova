<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next tenant-scoped, sequential staff number
 * (ADR-115; ADR-206; 25-hr-and-workforce-domain.md §2) — `STF-%08d`.
 */
final class StaffNumberSequence
{
    private const PREFIX = 'STF-';

    public function next(string $tenantId): string
    {
        DB::table('hr_staff_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update hr_staff_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('hr_staff_number_counters row missing for tenant after upsert (ADR-115).');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
