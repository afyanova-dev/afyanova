<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Query;

use App\Platform\Queueing\Contracts\DTO\QueueDto;
use App\Platform\Queueing\Domain\Repository\QueueRepository;

final class GetQueueHandler
{
    public function __construct(
        private readonly QueueRepository $queueRepository,
    ) {}

    public function __invoke(GetQueueQuery $query): ?QueueDto
    {
        $queue = $this->queueRepository->findById($query->queueId);

        if ($queue === null) {
            return null;
        }

        return new QueueDto(
            id: $queue->id,
            tenantId: $queue->tenant_id,
            facilityId: $queue->facility_id,
            code: $queue->code,
            name: $queue->name,
            departmentId: $queue->department_id,
            serviceCapability: $queue->service_capability,
            ticketPrefix: $queue->ticket_prefix,
            isActive: $queue->is_active,
            createdAt: $queue->created_at,
        );
    }
}
