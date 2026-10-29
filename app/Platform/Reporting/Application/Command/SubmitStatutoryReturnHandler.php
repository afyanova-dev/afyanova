<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Command;

use App\Platform\Reporting\Contracts\Event\StatutoryReturnSubmitted;
use App\Platform\Reporting\Domain\Exception\StatutoryReturnNotFound;
use App\Platform\Reporting\Domain\Model\StatutoryReturn;
use App\Platform\Reporting\Domain\ValueObject\StatutoryReturnStatus;
use App\Shared\Events\OutboxWriter;
use Carbon\CarbonImmutable;

final class SubmitStatutoryReturnHandler
{
    public function __construct(
        private readonly ?OutboxWriter $outbox = null,
    ) {}

    public function __invoke(SubmitStatutoryReturnCommand $command): StatutoryReturn
    {
        /** @var StatutoryReturn|null $return */
        $return = StatutoryReturn::query()
            ->where('tenant_id', $command->tenantId)
            ->where('id', $command->returnId)
            ->with('lines')
            ->first();

        if ($return === null) {
            throw new StatutoryReturnNotFound("Statutory return '{$command->returnId}' not found.");
        }

        $now = CarbonImmutable::now();

        if ($return->submitted_dataset === null) {
            $lines = $return->lines->map(fn ($l) => [
                'data_element_code' => $l->data_element_code,
                'category_option_combo' => $l->category_option_combo,
                'value' => (float) $l->value,
                'comment' => $l->comment,
            ])->all();

            $return->submitted_dataset = [
                'framework' => $return->framework->value,
                'period_key' => $return->period_key,
                'reporting_unit_code' => $return->reporting_unit_code,
                'lines' => $lines,
                'frozen_at' => $now->toIso8601String(),
            ];
        }

        $return->transitionTo(StatutoryReturnStatus::SUBMITTED);
        $return->submitted_by = $command->submittedBy;
        $return->submitted_at = $now;
        $return->save();

        $this->outbox?->write(new StatutoryReturnSubmitted(
            tenantId: $return->tenant_id,
            returnId: (string) $return->id,
            returnNumber: $return->return_number,
            framework: $return->framework->value,
            periodKey: $return->period_key,
            reportingUnitCode: $return->reporting_unit_code,
            submittedAt: $now,
        ));

        return $return;
    }
}
