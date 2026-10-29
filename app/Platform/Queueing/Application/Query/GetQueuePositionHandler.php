<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Query;

use App\Platform\Queueing\Domain\Enum\QueuePriority;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Platform\Queueing\Domain\Repository\QueueEntryRepository;

final readonly class GetQueuePositionHandler
{
    public function __construct(
        private QueueEntryRepository $queueEntryRepository,
    ) {}

    public function __invoke(GetQueuePositionQuery $query): int
    {
        $entry = $this->queueEntryRepository->findById($query->entryId);

        if ($entry === null || $entry->status->isTerminal()) {
            return 0;
        }

        if ($entry->status === QueueStatus::CALLED || $entry->status === QueueStatus::IN_SERVICE) {
            return 0;
        }

        // Active callable tickets in same queue
        $candidates = QueueEntry::query()
            ->where('queue_id', $entry->queue_id)
            ->whereIn('status', [
                QueueStatus::WAITING->value,
                QueueStatus::NO_RESPONSE->value,
                QueueStatus::SKIPPED->value,
            ])
            ->get();

        $entryWeight = $this->priorityWeight($entry->priority);
        $aheadCount = 0;

        foreach ($candidates as $candidate) {
            if ($candidate->id === $entry->id) {
                continue;
            }

            $candidateWeight = $this->priorityWeight($candidate->priority);

            if ($candidateWeight > $entryWeight) {
                $aheadCount++;
            } elseif ($candidateWeight === $entryWeight) {
                if ($candidate->created_at < $entry->created_at) {
                    $aheadCount++;
                } elseif ($candidate->created_at->equalTo($entry->created_at) && strcmp($candidate->ticket_number, $entry->ticket_number) < 0) {
                    $aheadCount++;
                }
            }
        }

        return $aheadCount + 1;
    }

    private function priorityWeight(QueuePriority $priority): int
    {
        return match ($priority) {
            QueuePriority::EMERGENCY => 4,
            QueuePriority::URGENT => 3,
            QueuePriority::PRIORITY => 2,
            QueuePriority::ROUTINE => 1,
        };
    }
}
