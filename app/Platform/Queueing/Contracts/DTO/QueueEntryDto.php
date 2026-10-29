<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Contracts\DTO;

use Carbon\CarbonImmutable;

final readonly class QueueEntryDto
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $facilityId,
        public string $queueId,
        public string $encounterId,
        public string $patientId,
        public string $ticketNumber,
        public string $priority,
        public string $status,
        public ?string $holdReason,
        public ?string $holdProvenance,
        public ?string $clearanceProvenance,
        public ?string $sourceOrderRef,
        public ?string $orderType,
        public ?CarbonImmutable $calledAt,
        public ?string $calledRoomId,
        public ?string $calledStaffId,
        public ?CarbonImmutable $serviceStartedAt,
        public ?CarbonImmutable $completedAt,
        public int $callAttempts,
        public CarbonImmutable $createdAt,
    ) {}
}
