<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Event;

use Carbon\CarbonImmutable;

final readonly class NotificationDispatched
{
    public function __construct(
        public string $tenantId,
        public string $notificationId,
        public string $templateKey,
        public string $channel,
        public string $provider,
        public ?string $maskedDestination,
        public CarbonImmutable $dispatchedAt,
    ) {}
}
