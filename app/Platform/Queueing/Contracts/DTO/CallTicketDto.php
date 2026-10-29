<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\DTO;

final readonly class CallTicketDto
{
    public function __construct(
        public string $entryId,
        public string $roomId,
        public ?string $staffId = null,
    ) {}
}
