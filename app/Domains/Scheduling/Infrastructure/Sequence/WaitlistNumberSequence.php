<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next tenant-scoped, sequential waitlist number
 * (ADR-115; 22-scheduling-and-appointments-domain.md §24) — `WTL-%08d`.
 */
final class WaitlistNumberSequence
{
    private const PREFIX = 'WTL-';

    public function next(string $tenantId): string
    {
        DB::table('sch_waitlist_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update sch_waitlist_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('sch_waitlist_number_counters row missing for tenant after upsert (ADR-115).');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
