<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Command;

use App\Platform\Reporting\Contracts\Event\StatutoryReturnGenerated;
use App\Platform\Reporting\Domain\Model\StatutoryReturn;
use App\Platform\Reporting\Domain\Model\StatutoryReturnLine;
use App\Platform\Reporting\Domain\ValueObject\StatutoryReturnStatus;
use App\Platform\Reporting\Infrastructure\Sequence\ReturnNumberSequence;
use App\Shared\Events\OutboxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class BuildStatutoryReturnHandler
{
    public function __construct(
        private readonly ReturnNumberSequence $sequence,
        private readonly ?OutboxWriter $outbox = null,
    ) {}

    public function __invoke(BuildStatutoryReturnCommand $command): StatutoryReturn
    {
        return DB::transaction(function () use ($command): StatutoryReturn {
            $returnNumber = $this->sequence->next($command->tenantId, substr($command->periodKey, 0, 4));
            $hasLines = count($command->lines) > 0;
            $status = $hasLines ? StatutoryReturnStatus::GENERATED : StatutoryReturnStatus::DRAFT;
            $now = CarbonImmutable::now();

            $submittedDataset = $hasLines ? [
                'framework' => $command->framework->value,
                'period_key' => $command->periodKey,
                'reporting_unit_code' => $command->reportingUnitCode,
                'lines' => $command->lines,
                'frozen_at' => $now->toIso8601String(),
            ] : null;

            $return = StatutoryReturn::create([
                'tenant_id' => $command->tenantId,
                'return_number' => $returnNumber,
                'framework' => $command->framework,
                'period_type' => $command->periodType,
                'period_key' => $command->periodKey,
                'facility_id' => $command->facilityId,
                'reporting_unit_code' => $command->reportingUnitCode,
                'status' => $status,
                'submitted_dataset' => $submittedDataset,
                'generated_by' => $command->generatedBy,
                'reference_config_version' => $command->referenceConfigVersion,
            ]);

            foreach ($command->lines as $line) {
                StatutoryReturnLine::create([
                    'tenant_id' => $command->tenantId,
                    'return_id' => $return->id,
                    'data_element_code' => $line['data_element_code'],
                    'category_option_combo' => $line['category_option_combo'] ?? 'default',
                    'value' => (float) $line['value'],
                    'comment' => $line['comment'] ?? null,
                ]);
            }

            if ($hasLines && $this->outbox !== null) {
                $this->outbox->write(new StatutoryReturnGenerated(
                    tenantId: $return->tenant_id,
                    returnId: (string) $return->id,
                    returnNumber: $return->return_number,
                    framework: $return->framework->value,
                    periodKey: $return->period_key,
                    reportingUnitCode: $return->reporting_unit_code,
                    lineCount: count($command->lines),
                    generatedAt: $now,
                ));
            }

            return $return;
        });
    }
}
