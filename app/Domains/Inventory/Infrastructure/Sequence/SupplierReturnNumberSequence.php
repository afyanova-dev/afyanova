<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SupplierReturnNumberSequence
{
    private const PREFIX = 'RET-';

    public function next(string $tenantId): string
    {
        DB::table('inv_supplier_return_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update inv_supplier_return_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('Supplier return number counter is missing for this tenant.');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
