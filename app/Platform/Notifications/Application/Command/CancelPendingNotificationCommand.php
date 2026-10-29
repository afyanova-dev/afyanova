<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Application\Command;

final readonly class CancelPendingNotificationCommand
{
    public function __construct(public string $requestId) {}
}
