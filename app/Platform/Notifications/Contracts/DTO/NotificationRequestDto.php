<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Contracts\DTO;

use Carbon\CarbonImmutable;

final readonly class NotificationRequestDto
{
    /**
     * @param  list<DeliveryAttemptDto>  $attempts
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $templateKey,
        public int $templateVersion,
        public string $recipientType,
        public string $recipientId,
        public string $onBehalfOfDomain,
        public string $onBehalfOfEntity,
        public string $priority,
        public string $status,
        public ?string $suppressionReason,
        public array $attempts,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $updatedAt,
    ) {}
}
