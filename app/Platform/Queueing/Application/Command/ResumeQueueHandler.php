<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Platform\Queueing\Contracts\DTO\QueueDto;
use App\Platform\Queueing\Domain\Exception\QueueNotFoundException;
use App\Platform\Queueing\Domain\Repository\QueueRepository;

final readonly class ResumeQueueHandler
{
    public function __construct(
        private QueueRepository $queueRepository,
    ) {}

    public function __invoke(ResumeQueueCommand $command): QueueDto
    {
        $queue = $this->queueRepository->findById($command->queueId)
            ?? throw new QueueNotFoundException("Queue not found: {$command->queueId}");

        $queue->resume();
        $this->queueRepository->save($queue);

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
            operatingStatus: $queue->operating_status->value,
            pauseReason: $queue->pause_reason,
            pausedAt: $queue->paused_at,
            closedAt: $queue->closed_at,
            createdAt: $queue->created_at,
        );
    }
}
