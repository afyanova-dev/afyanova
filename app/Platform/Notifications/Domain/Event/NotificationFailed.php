<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Event;

use Carbon\CarbonImmutable;

final readonly class NotificationFailed
{
    public function __construct(
        public string $tenantId,
        public string $notificationId,
        public string $channel,
        public string $provider,
        public ?string $errorCode,
        public ?string $errorMessage,
        public CarbonImmutable $failedAt,
    ) {}
}
