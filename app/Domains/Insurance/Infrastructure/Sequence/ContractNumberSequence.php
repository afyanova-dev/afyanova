<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates the next tenant-scoped, human-readable contract number
 * (ADR-115; ADR-203) — `PCT-%08d`, tenant-wide unique.
 *
 * Raw counter SQL, confined to Infrastructure/ (IC §9).
 * Runs inside the `CreatePayerContract` command transaction so `app.tenant_id`
 * is set and the counter row's RLS predicate matches. The UPDATE row-locks;
 * concurrent allocations for one tenant serialize on that row.
 */
final class ContractNumberSequence
{
    private const PREFIX = 'PCT-';

    public function next(string $tenantId): string
    {
        DB::table('ins_contract_number_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'next_value' => 1,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update ins_contract_number_counters
                set next_value = next_value + 1, version = version + 1, updated_at = now()
              where tenant_id = ?
          returning next_value - 1 as allocated',
            [$tenantId],
        );

        if ($row === null) {
            throw new RuntimeException('ins_contract_number_counters row missing for tenant after upsert (ADR-115).');
        }

        return self::PREFIX.sprintf('%08d', (int) $row->allocated);
    }
}
