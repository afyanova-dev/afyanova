<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

final readonly class CallNextTicketCommand
{
    public function __construct(
        public string $queueId,
        public string $roomId,
        public ?string $staffId = null,
    ) {}
}
