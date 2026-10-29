<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Infrastructure\Sequence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ReportRunNumberSequence
{
    private const PREFIX = 'RPT-';

    public function next(string $tenantId, ?string $periodKey = null): string
    {
        $period = $periodKey ?? date('Y');

        DB::table('rpt_report_run_number_counters')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'period_key' => $period,
            'current_val' => 0,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'update rpt_report_run_number_counters
                set current_val = current_val + 1, version = version + 1, updated_at = now()
              where tenant_id = ? and period_key = ?
          returning current_val as allocated',
            [$tenantId, $period],
        );

        if ($row === null) {
            throw new RuntimeException('rpt_report_run_number_counters row missing for tenant after upsert.');
        }

        return self::PREFIX.$period.'-'.sprintf('%06d', (int) $row->allocated);
    }
}
