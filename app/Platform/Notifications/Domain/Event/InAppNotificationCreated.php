<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Event;

use Carbon\CarbonImmutable;

final readonly class InAppNotificationCreated
{
    public function __construct(
        public string $tenantId,
        public string $inAppId,
        public string $userId,
        public string $title,
        public CarbonImmutable $createdAt,
    ) {}
}
