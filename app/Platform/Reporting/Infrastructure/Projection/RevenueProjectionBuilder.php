<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Infrastructure\Projection;

use App\Platform\Reporting\Domain\Model\FinancialDailyRevenue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RevenueProjectionBuilder
{
    public function rebuild(string $tenantId, ?string $facilityId = null): int
    {
        $now = CarbonImmutable::now();

        if (! DB::getSchemaBuilder()->hasTable('bill_invoices')) {
            return 0;
        }

        $query = DB::table('bill_invoices')
            ->where('tenant_id', $tenantId)
            ->where('status', 'ISSUED')
            ->when($facilityId !== null, fn ($q) => $q->where('facility_id', $facilityId))
            ->selectRaw("
                facility_id,
                COALESCE(DATE(issued_at), CURRENT_DATE) as rev_date,
                COALESCE(payer_type, 'SELF_PAY') as p_method,
                SUM(gross) as total_gross,
                SUM(net) as total_net
            ")
            ->groupByRaw("facility_id, COALESCE(DATE(issued_at), CURRENT_DATE), COALESCE(payer_type, 'SELF_PAY')");

        $rows = $query->get();
        $processed = 0;

        $schema = DB::getSchemaBuilder();
        $hasClaims = $schema->hasTable('ins_claims');
        $hasAdjustments = $schema->hasTable('bill_adjustments');

        foreach ($rows as $row) {
            $insuranceClaimed = 0.0;
            if ($hasClaims) {
                $claimSum = DB::table('ins_claims')
                    ->where('tenant_id', $tenantId)
                    ->where('facility_id', (string) $row->facility_id)
                    ->whereRaw('COALESCE(DATE(submitted_at), CURRENT_DATE) = ?', [(string) $row->rev_date])
                    ->whereIn('status', ['SUBMITTED', 'READY', 'SETTLED', 'ADJUDICATED'])
                    ->sum('total_claimed_amount');
                $insuranceClaimed = (float) $claimSum;
            }

            $adjustmentsTotal = 0.0;
            if ($hasAdjustments) {
                $adjSum = DB::table('bill_adjustments')
                    ->where('tenant_id', $tenantId)
                    ->where('facility_id', (string) $row->facility_id)
                    ->whereRaw('COALESCE(DATE(approved_at), CURRENT_DATE) = ?', [(string) $row->rev_date])
                    ->where('status', 'APPLIED')
                    ->sum(DB::raw("CASE WHEN direction = 'CREDIT' THEN amount ELSE -amount END"));
                $adjustmentsTotal = (float) $adjSum;
            }

            FinancialDailyRevenue::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'facility_id' => (string) $row->facility_id,
                    'revenue_date' => (string) $row->rev_date,
                    'payment_method' => (string) $row->p_method,
                ],
                [
                    'total_billed' => (float) $row->total_gross,
                    'total_collected' => (float) $row->total_net,
                    'total_insurance_claimed' => $insuranceClaimed,
                    'total_adjustments' => $adjustmentsTotal,
                    'as_of_time' => $now,
                ]
            );
            $processed++;
        }

        return $processed;
    }
}
