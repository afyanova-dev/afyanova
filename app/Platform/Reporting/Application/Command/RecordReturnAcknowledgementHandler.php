<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Command;

use App\Platform\Reporting\Contracts\Event\StatutoryReturnAcknowledged;
use App\Platform\Reporting\Domain\Exception\StatutoryReturnNotFound;
use App\Platform\Reporting\Domain\Model\StatutoryReturn;
use App\Platform\Reporting\Domain\ValueObject\StatutoryReturnStatus;
use App\Shared\Events\OutboxWriter;
use Carbon\CarbonImmutable;

final class RecordReturnAcknowledgementHandler
{
    public function __construct(
        private readonly ?OutboxWriter $outbox = null,
    ) {}

    public function __invoke(RecordReturnAcknowledgementCommand $command): StatutoryReturn
    {
        /** @var StatutoryReturn|null $return */
        $return = StatutoryReturn::query()
            ->where('tenant_id', $command->tenantId)
            ->where('id', $command->returnId)
            ->first();

        if ($return === null) {
            throw new StatutoryReturnNotFound("Statutory return '{$command->returnId}' not found.");
        }

        $now = CarbonImmutable::now();
        $return->transitionTo(StatutoryReturnStatus::ACKNOWLEDGED);
        $return->submission_receipt = $command->receiptPayload ?? ['acknowledgement_ref' => $command->acknowledgementRef];
        $return->acknowledged_at = $now;
        $return->save();

        $this->outbox?->write(new StatutoryReturnAcknowledged(
            tenantId: $return->tenant_id,
            returnId: (string) $return->id,
            returnNumber: $return->return_number,
            framework: $return->framework->value,
            acknowledgementRef: $command->acknowledgementRef,
            acknowledgedAt: $now,
        ));

        return $return;
    }
}
