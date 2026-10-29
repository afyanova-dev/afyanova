<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Infrastructure\Adapter;

use App\Platform\Notifications\Contracts\DTO\InAppNotificationDto;
use App\Platform\Notifications\Domain\Port\InAppBroadcasterPort;
use Illuminate\Support\Facades\Log;

final class ReverbInAppBroadcaster implements InAppBroadcasterPort
{
    /** @var list<array{tenantId: string, userId: string, notification: InAppNotificationDto}> */
    public static array $broadcasts = [];

    public function broadcastToUser(string $tenantId, string $userId, InAppNotificationDto $dto): void
    {
        self::$broadcasts[] = [
            'tenantId' => $tenantId,
            'userId' => $userId,
            'notification' => $dto,
        ];

        Log::info("In-App Broadcasted: User [{$userId}], Title [{$dto->title}]");
    }

    public static function reset(): void
    {
        self::$broadcasts = [];
    }
}
