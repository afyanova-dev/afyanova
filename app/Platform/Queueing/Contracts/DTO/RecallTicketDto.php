<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\DTO;

final readonly class RecallTicketDto
{
    public function __construct(
        public string $entryId,
        public ?string $roomId = null,
        public ?string $staffId = null,
    ) {}
}
