<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Command;

use App\Platform\Reporting\Domain\Exception\StatutoryReturnNotFound;
use App\Platform\Reporting\Domain\Model\StatutoryReturn;
use App\Platform\Reporting\Domain\Model\StatutoryReturnLine;
use App\Platform\Reporting\Domain\ValueObject\StatutoryReturnStatus;
use App\Platform\Reporting\Infrastructure\Sequence\ReturnNumberSequence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ResubmitStatutoryReturnHandler
{
    public function __construct(
        private readonly ReturnNumberSequence $sequence,
    ) {}

    public function __invoke(ResubmitStatutoryReturnCommand $command): StatutoryReturn
    {
        return DB::transaction(function () use ($command): StatutoryReturn {
            /** @var StatutoryReturn|null $original */
            $original = StatutoryReturn::query()
                ->where('tenant_id', $command->tenantId)
                ->where('id', $command->originalReturnId)
                ->first();

            if ($original === null) {
                throw new StatutoryReturnNotFound("Original statutory return '{$command->originalReturnId}' not found.");
            }

            $now = CarbonImmutable::now();
            $newReturnNumber = $this->sequence->next($command->tenantId, substr($original->period_key, 0, 4));

            $newSubmittedDataset = [
                'framework' => $original->framework->value,
                'period_key' => $original->period_key,
                'reporting_unit_code' => $original->reporting_unit_code,
                'lines' => $command->lines,
                'supersedes' => $original->return_number,
                'frozen_at' => $now->toIso8601String(),
            ];

            $newReturn = StatutoryReturn::create([
                'tenant_id' => $command->tenantId,
                'return_number' => $newReturnNumber,
                'framework' => $original->framework,
                'period_type' => $original->period_type,
                'period_key' => $original->period_key,
                'facility_id' => $original->facility_id,
                'reporting_unit_code' => $original->reporting_unit_code,
                'status' => StatutoryReturnStatus::GENERATED,
                'supersedes_return_id' => $original->id,
                'submitted_dataset' => $newSubmittedDataset,
                'generated_by' => $command->submittedBy,
                'reference_config_version' => 'v1.0',
            ]);

            foreach ($command->lines as $line) {
                StatutoryReturnLine::create([
                    'tenant_id' => $command->tenantId,
                    'return_id' => $newReturn->id,
                    'data_element_code' => $line['data_element_code'],
                    'category_option_combo' => $line['category_option_combo'] ?? 'default',
                    'value' => (float) $line['value'],
                    'comment' => $line['comment'] ?? null,
                ]);
            }

            $original->status = StatutoryReturnStatus::SUPERSEDED;
            $original->superseded_by_return_id = $newReturn->id;
            $original->save();

            return $newReturn;
        });
    }
}
