<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Event;

use Carbon\CarbonImmutable;

final readonly class NotificationSuppressed
{
    public function __construct(
        public string $tenantId,
        public string $notificationId,
        public string $suppressionReason,
        public ?string $details,
        public CarbonImmutable $suppressedAt,
    ) {}
}
