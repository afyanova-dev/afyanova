<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Contracts\DTO;

final readonly class BulkNotificationResponseDto
{
    /**
     * @param  list<string>  $requestIds
     */
    public function __construct(
        public int $queuedCount,
        public array $requestIds,
    ) {}
}
