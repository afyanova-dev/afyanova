<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Domains\Patient\Contracts\PatientApi;
use App\Models\User;
use App\Platform\Queueing\Application\Query\GetQueueStatusBreakdownQuery;
use App\Platform\Queueing\Application\Query\GetQueueWaitMetricsQuery;
use App\Platform\Queueing\Contracts\DTO\QueueWaitMetricsDto;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class QueueDetailController
{
    use PresentsQueueEntries;

    public function __invoke(
        Request $request,
        TenantContext $context,
        QueryBus $queryBus,
        PatientApi $patientApi,
        string $queue,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');
        abort_unless($user->can('queue.ticket.view'), 403, 'You do not have access to service queues.');

        /** @var Queue */
        $target = Queue::query()
            ->where('facility_id', $facilityId)
            ->findOrFail($queue);

        try {
            /** @var array<string, int> $breakdown */
            $breakdown = $queryBus->dispatch(new GetQueueStatusBreakdownQuery($facilityId, $target->id));
        } catch (\Throwable) {
            $breakdown = array_fill_keys(
                array_map(static fn (QueueStatus $status): string => $status->value, QueueStatus::cases()),
                0,
            );
        }

        try {
            /** @var QueueWaitMetricsDto $metrics */
            $metrics = $queryBus->dispatch(new GetQueueWaitMetricsQuery($target->id));
            $waitMetrics = [
                'averageWaitMinutes' => $metrics->averageWaitSeconds > 0 ? round($metrics->averageWaitSeconds / 60, 1) : null,
                'averageServiceMinutes' => $metrics->averageServiceSeconds > 0 ? round($metrics->averageServiceSeconds / 60, 1) : null,
                'activeTickets' => $metrics->activeTicketsCount,
                'completedToday' => $metrics->completedTodayCount,
                'leftWithoutBeingSeen' => $metrics->lwbsCount,
            ];
        } catch (\Throwable) {
            $waitMetrics = [
                'averageWaitMinutes' => null,
                'averageServiceMinutes' => null,
                'activeTickets' => 0,
                'completedToday' => 0,
                'leftWithoutBeingSeen' => 0,
            ];
        }

        $groups = $this->presentQueueEntries($facilityId, $target->id, $patientApi);

        $capability = $target->service_capability;

        return Inertia::render('ServiceQueues/Show', [
            'queue' => [
                'queueId' => $target->id,
                'queue' => $target->name,
                'code' => $target->code,
                'service' => is_string($capability) && $capability !== ''
                    ? ucwords(strtolower(str_replace('_', ' ', $capability)))
                    : null,
                'operatingStatus' => $target->operating_status->value,
                'isActive' => $target->is_active,
            ],
            'breakdown' => $breakdown,
            'metrics' => $waitMetrics,
            'waitingEntries' => $groups['waiting'],
            'calledEntries' => $groups['called'],
            'inServiceEntries' => $groups['inService'],
            'holdEntries' => $groups['onHold'],
            'queues' => $this->presentQueueOptions($facilityId),
            'rooms' => $this->presentRooms($facilityId),
        ]);
    }
}
