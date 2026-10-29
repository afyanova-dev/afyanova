<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\DTO;

final readonly class CallNextDto
{
    public function __construct(
        public string $queueId,
        public string $roomId,
        public ?string $staffId = null,
    ) {}
}
