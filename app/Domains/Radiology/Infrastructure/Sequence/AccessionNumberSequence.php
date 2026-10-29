<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next tenant-scoped, human-readable imaging accession number
 * (ADR-166 D-C) — `RADAC-%08d`, tenant-wide unique (the `OrderNumberSequence` /
 * `AccessionNumberSequence` [Laboratory] precedent — same class shape here).
 *
 * The accession number is the radiology STUDY processing identity, distinct
 * from the `RAD-%08d` order number (§29). Must run inside the
 * `ScheduleImagingStudy` command transaction (IC §11.3). Written only by this
 * class; no other module reads or writes `rad_accession_number_counters`
 * (IC §2.1).
 */
final class AccessionNumberSequence
{
    private const PREFIX = 'RADAC-';

    public function next(string $tenantId): string
    {
        DB::table('rad_accession_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update rad_accession_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('rad_accession_number_counters row missing for tenant after upsert (ADR-166).');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
