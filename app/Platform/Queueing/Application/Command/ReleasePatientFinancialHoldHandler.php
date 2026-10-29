<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Platform\Queueing\Domain\Enum\HoldReason;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Platform\Queueing\Domain\Repository\QueueEntryRepository;

final class ReleasePatientFinancialHoldHandler
{
    public function __construct(
        private readonly QueueEntryRepository $queueEntryRepository,
    ) {}

    public function __invoke(ReleasePatientFinancialHoldCommand $command): int
    {
        /** @var list<QueueEntry> $entries */
        $entries = QueueEntry::query()
            ->where('patient_id', $command->patientId)
            ->where('status', QueueStatus::HOLD->value)
            ->where('hold_reason', HoldReason::FINANCIAL_HOLD->value)
            ->get()
            ->all();

        $count = 0;
        foreach ($entries as $entry) {
            $entry->releaseHold("Payment:{$command->paymentId}");
            $this->queueEntryRepository->save($entry);
            $count++;
        }

        return $count;
    }
}
