<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next tenant-scoped purchase-requisition number (ADR-171 D-E) —
 * `PR-%08d`, the ADR-115 / `MovementNumberSequence` precedent. Must run inside
 * the command transaction (Tenancy::run — IC §11.3). Written only by this class.
 */
final class RequisitionNumberSequence
{
    private const PREFIX = 'PR-';

    public function next(string $tenantId): string
    {
        DB::table('pcr_requisition_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update pcr_requisition_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('pcr_requisition_number_counters row missing for tenant after upsert (ADR-171 D-E).');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
