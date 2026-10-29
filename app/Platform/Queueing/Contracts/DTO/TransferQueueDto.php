<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\DTO;

final readonly class TransferQueueDto
{
    public function __construct(
        public string $entryId,
        public string $destinationQueueId,
        public ?string $sourceOrderRef = null,
        public ?string $orderType = null,
        public ?string $reason = null,
    ) {}
}
