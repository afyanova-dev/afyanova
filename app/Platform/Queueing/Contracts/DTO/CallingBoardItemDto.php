<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\DTO;

use Carbon\CarbonImmutable;

final readonly class CallingBoardItemDto
{
    public function __construct(
        public string $entryId,
        public string $ticketNumber,
        public string $queueCode,
        public string $queueName,
        public string $status,
        public ?string $calledRoomId,
        public ?CarbonImmutable $calledAt,
        public string $priority,
    ) {}
}
