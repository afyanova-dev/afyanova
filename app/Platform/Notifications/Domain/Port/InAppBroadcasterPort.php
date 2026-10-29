<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Port;

use App\Platform\Notifications\Contracts\DTO\InAppNotificationDto;

interface InAppBroadcasterPort
{
    public function broadcastToUser(string $tenantId, string $userId, InAppNotificationDto $dto): void;
}
