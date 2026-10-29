<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next tenant-scoped receipt number (ADR-115; ADR-191 B5-C) —
 * `RCT-%08d`, tenant-wide unique, minted only inside the `RecordPayment`
 * command transaction. The `InvoiceNumberSequence` shape verbatim. Written
 * only by this class.
 */
final class ReceiptNumberSequence
{
    private const PREFIX = 'RCT-';

    public function next(string $tenantId): string
    {
        DB::table('bill_receipt_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update bill_receipt_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('bill_receipt_number_counters row missing for tenant after upsert (ADR-115).');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
