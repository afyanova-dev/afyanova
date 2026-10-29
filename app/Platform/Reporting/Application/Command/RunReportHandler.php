<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Command;

use App\Platform\Reporting\Domain\Exception\ReportDefinitionNotFound;
use App\Platform\Reporting\Domain\Model\BedOccupancyDaily;
use App\Platform\Reporting\Domain\Model\EncounterDailyAggregate;
use App\Platform\Reporting\Domain\Model\FinancialDailyRevenue;
use App\Platform\Reporting\Domain\Model\LabTatDaily;
use App\Platform\Reporting\Domain\Model\ReportDefinition;
use App\Platform\Reporting\Domain\Model\ReportRun;
use App\Platform\Reporting\Domain\ValueObject\ReportRunStatus;
use App\Platform\Reporting\Infrastructure\Sequence\ReportRunNumberSequence;
use Carbon\CarbonImmutable;
use Throwable;

final class RunReportHandler
{
    public function __construct(
        private readonly ReportRunNumberSequence $sequence,
    ) {}

    public function __invoke(RunReportCommand $command): ReportRun
    {
        /** @var ReportDefinition|null $def */
        $def = ReportDefinition::query()
            ->where('tenant_id', $command->tenantId)
            ->where('code', $command->reportDefinitionCode)
            ->first();

        if ($def === null) {
            throw new ReportDefinitionNotFound("Report definition '{$command->reportDefinitionCode}' not found.");
        }

        $runNumber = $this->sequence->next($command->tenantId);
        $asOfTime = CarbonImmutable::now();

        $run = ReportRun::create([
            'tenant_id' => $command->tenantId,
            'run_number' => $runNumber,
            'report_definition_id' => $def->id,
            'report_definition_code' => $def->code,
            'parameters' => $command->parameters ?? [],
            'status' => ReportRunStatus::RUNNING,
            'requested_by' => $command->requestedBy,
            'facility_id' => $command->facilityId,
            'as_of_time' => $asOfTime,
            'data_freshness_lag_seconds' => 0,
            'started_at' => $asOfTime,
        ]);

        try {
            $dataset = $this->executeQuery($command->tenantId, $def, $command->parameters, $command->facilityId);
            $rowCount = count($dataset);
            $completedAt = CarbonImmutable::now();

            $run->update([
                'status' => ReportRunStatus::COMPLETED,
                'result_dataset' => ['rows' => $dataset, 'metadata' => ['total' => $rowCount]],
                'row_count' => $rowCount,
                'completed_at' => $completedAt,
            ]);

            return $run->fresh() ?? $run;
        } catch (Throwable $e) {
            $failedAt = CarbonImmutable::now();
            $run->update([
                'status' => ReportRunStatus::FAILED,
                'failure_reason' => $e->getMessage(),
                'completed_at' => $failedAt,
            ]);

            return $run->fresh() ?? $run;
        }
    }

    /**
     * @param  array<string, mixed>|null  $params
     * @return list<array<string, mixed>>
     */
    private function executeQuery(string $tenantId, ReportDefinition $def, ?array $params, ?string $facilityId): array
    {
        $targetFacility = $params['facility_id'] ?? $facilityId;

        /** @var list<array<string, mixed>> $rows */
        $rows = match ($def->data_mart_code) {
            'mart_encounters' => array_values(EncounterDailyAggregate::query()
                ->where('tenant_id', $tenantId)
                ->when($targetFacility !== null, fn ($q) => $q->where('facility_id', $targetFacility))
                ->limit(500)
                ->get()
                ->toArray()),

            'mart_revenue' => array_values(FinancialDailyRevenue::query()
                ->where('tenant_id', $tenantId)
                ->when($targetFacility !== null, fn ($q) => $q->where('facility_id', $targetFacility))
                ->limit(500)
                ->get()
                ->toArray()),

            'mart_bed_occupancy' => array_values(BedOccupancyDaily::query()
                ->where('tenant_id', $tenantId)
                ->when($targetFacility !== null, fn ($q) => $q->where('facility_id', $targetFacility))
                ->limit(500)
                ->get()
                ->toArray()),

            'mart_lab_tat' => array_values(LabTatDaily::query()
                ->where('tenant_id', $tenantId)
                ->when($targetFacility !== null, fn ($q) => $q->where('facility_id', $targetFacility))
                ->limit(500)
                ->get()
                ->toArray()),

            default => [
                ['metric' => 'sample', 'value' => 100, 'timestamp' => now()->toIso8601String()],
            ],
        };

        return $rows;
    }
}
