<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Contracts\DTO;

use Carbon\CarbonImmutable;

final readonly class InAppNotificationDto
{
    public function __construct(
        public string $id,
        public string $userId,
        public ?string $notificationRequestId,
        public string $title,
        public string $body,
        public ?string $actionUrl,
        public ?CarbonImmutable $readAt,
        public CarbonImmutable $createdAt,
    ) {}
}
