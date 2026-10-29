<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Command;

use App\Platform\Queueing\Contracts\DTO\QueueDto;
use App\Platform\Queueing\Domain\Enum\QueueOperatingStatus;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;

final class RegisterQueueHandler
{
    public function __construct(
        private readonly QueueRepository $queueRepository,
        private readonly Container $container,
    ) {}

    private function tenantContext(): TenantContext
    {
        return $this->container->make(TenantContext::class);
    }

    public function __invoke(RegisterQueueCommand $command): QueueDto
    {
        $tenantId = $this->tenantContext()->tenantId();

        $queue = $this->queueRepository->findByCode($command->facilityId, $command->code);

        if ($queue === null) {
            $queue = new Queue([
                'tenant_id' => $tenantId,
                'facility_id' => $command->facilityId,
                'code' => $command->code,
                'name' => $command->name,
                'department_id' => $command->departmentId,
                'service_capability' => $command->serviceCapability,
                'ticket_prefix' => $command->ticketPrefix,
                'is_active' => $command->isActive,
                'operating_status' => QueueOperatingStatus::ACTIVE,
            ]);
        } else {
            $queue->name = $command->name;
            $queue->department_id = $command->departmentId;
            $queue->service_capability = $command->serviceCapability;
            $queue->ticket_prefix = $command->ticketPrefix;
            $queue->is_active = $command->isActive;
        }

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
