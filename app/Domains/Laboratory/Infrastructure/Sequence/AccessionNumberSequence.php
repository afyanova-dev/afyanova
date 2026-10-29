<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next tenant-scoped, human-readable laboratory accession number
 * (ADR-158 D-D) — `LABSP-%08d`, tenant-wide unique (the `OrderNumberSequence` /
 * `CaseNumberSequence` precedent — same class shape here).
 *
 * The accession number is the laboratory PROCESSING identity, distinct from the
 * order number (§32–§33). Must run inside the CollectSpecimen command
 * transaction (IC §11.3). Written only by this class.
 */
final class AccessionNumberSequence
{
    private const PREFIX = 'LABSP-';

    public function next(string $tenantId): string
    {
        DB::table('lab_accession_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update lab_accession_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('lab_accession_number_counters row missing for tenant after upsert (ADR-158).');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
