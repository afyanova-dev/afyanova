<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Application\Command;

final readonly class MarkInAppReadCommand
{
    public function __construct(
        public string $notificationId,
        public string $userId,
    ) {}
}
