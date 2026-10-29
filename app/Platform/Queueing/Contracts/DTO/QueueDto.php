<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\DTO;

use Carbon\CarbonImmutable;

final readonly class QueueDto
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $facilityId,
        public string $code,
        public string $name,
        public ?string $departmentId,
        public ?string $serviceCapability,
        public string $ticketPrefix,
        public bool $isActive,
        public string $operatingStatus = 'ACTIVE',
        public ?string $pauseReason = null,
        public ?CarbonImmutable $pausedAt = null,
        public ?CarbonImmutable $closedAt = null,
        public ?CarbonImmutable $createdAt = null,
        /** @var list<string> */
        public array $roomNames = [],
    ) {}
}
