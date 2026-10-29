<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Contracts\DTO;

use Carbon\CarbonImmutable;

final readonly class DeliveryAttemptDto
{
    public function __construct(
        public string $id,
        public string $notificationRequestId,
        public string $channel,
        public string $provider,
        public ?string $providerMessageId,
        public ?string $maskedDestination,
        public string $status,
        public ?string $errorCode,
        public ?string $errorMessage,
        public CarbonImmutable $attemptedAt,
        public ?CarbonImmutable $deliveredAt,
    ) {}
}
