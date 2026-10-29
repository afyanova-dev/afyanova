<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next tenant-scoped quotation number (ADR-173 D-C) — `QUO-%08d`,
 * a stable internal handle (the supplier's own reference is the free
 * `supplier_quote_ref`). The ADR-115 / `PurchaseOrderNumberSequence` precedent.
 * Must run inside the command transaction. Written only by this class.
 */
final class QuotationNumberSequence
{
    private const PREFIX = 'QUO-';

    public function next(string $tenantId): string
    {
        DB::table('pcr_quotation_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update pcr_quotation_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('pcr_quotation_number_counters row missing for tenant after upsert (ADR-173 D-C).');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
