<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

final readonly class CancelEncounterQueueEntriesCommand
{
    public function __construct(
        public string $encounterId,
        public string $reason,
    ) {}
}
