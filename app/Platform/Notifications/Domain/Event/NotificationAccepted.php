<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Event;

use Carbon\CarbonImmutable;

final readonly class NotificationAccepted
{
    public function __construct(
        public string $tenantId,
        public string $notificationId,
        public string $templateKey,
        public string $recipientType,
        public string $recipientId,
        public string $priority,
        public ?string $dedupeKey,
        public CarbonImmutable $createdAt,
    ) {}
}
