<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Domains\Organization\Contracts\OrganizationApi;
use App\Domains\Patient\Contracts\PatientApi;
use App\Models\User;
use App\Platform\Queueing\Application\Query\GetFacilityFlowBoardQuery;
use App\Platform\Queueing\Contracts\DTO\FacilityFlowBoardDto;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ServiceQueuesController
{
    use PresentsQueueEntries;

    public function __invoke(
        Request $request,
        TenantContext $context,
        QueryBus $queryBus,
        PatientApi $patientApi,
        OrganizationApi $organizationApi,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');
        abort_unless($user->can('queue.ticket.view'), 403, 'You do not have access to patient flow.');

        $board = null;
        try {
            /** @var FacilityFlowBoardDto $board */
            $board = $queryBus->dispatch(new GetFacilityFlowBoardQuery($facilityId));
        } catch (\Throwable) {
            $board = null;
        }

        $groups = $this->presentQueueEntries($facilityId, null, $patientApi);
        $waitingEntries = $groups['waiting'];
        $calledEntries = $groups['called'];
        $inServiceEntries = $groups['inService'];
        $onHoldEntries = $groups['onHold'];
        $recallableEntries = $groups['recallable'];

        $queues = [];
        $totals = [
            'waiting' => count($waitingEntries),
            'inService' => count($inServiceEntries),
            'averageWaitMinutes' => null,
            'longestWaitMinutes' => null,
            'longestWaitPatient' => null,
        ];

        if ($board !== null) {
            $oldestByQueue = [];
            foreach ($waitingEntries as $entry) {
                $key = (string) $entry['queue'];
                if (! isset($oldestByQueue[$key])) {
                    $oldestByQueue[$key] = $entry;
                } elseif ($entry['waitMinutes'] > $oldestByQueue[$key]['waitMinutes']) {
                    $oldestByQueue[$key] = $entry;
                }
            }

            // The flow board carries counts and wait aggregates but no service
            // capability, so resolve it here for the human-readable Service column.
            $capabilities = Queue::query()
                ->where('facility_id', $facilityId)
                ->pluck('service_capability', 'id')
                ->all();

            $queues = array_map(static function (array $row) use ($oldestByQueue, $capabilities): array {
                $oldest = $oldestByQueue[$row['name']] ?? null;
                $hasWaiting = $row['waiting_count'] > 0;
                $capability = $capabilities[$row['queue_id']] ?? null;

                return [
                    'queueId' => $row['queue_id'],
                    'queue' => $row['name'],
                    'code' => $row['code'],
                    // Queue codes (TRG, OPD) are not a service description.
                    'service' => is_string($capability) && $capability !== ''
                        ? ucwords(strtolower(str_replace('_', ' ', $capability)))
                        : null,
                    'location' => null,
                    'waiting' => $row['waiting_count'],
                    'attending' => $row['in_service_count'],
                    // A queue with nobody waiting has no waits to average.
                    'averageWaitMinutes' => $hasWaiting ? $row['avg_wait_minutes'] : null,
                    'longestWaitMinutes' => $hasWaiting ? $row['longest_wait_minutes'] : null,
                    'longestWaitPatient' => $hasWaiting && $oldest !== null ? $oldest['patientName'] : null,
                    'operatingStatus' => $row['operating_status'],
                ];
            }, $board->queues);

            usort($queues, static fn (array $a, array $b): int => ($b['longestWaitMinutes'] ?? -1) <=> ($a['longestWaitMinutes'] ?? -1));

            $longest = null;
            foreach ($waitingEntries as $entry) {
                if ($longest === null || $entry['waitMinutes'] > $longest['waitMinutes']) {
                    $longest = $entry;
                }
            }

            $waits = array_column($waitingEntries, 'waitMinutes');
            $totals = [
                'waiting' => $board->totalWaiting,
                'inService' => $board->totalInService,
                // Nobody waiting means no average and no longest wait, not zeroes.
                'averageWaitMinutes' => $waits === [] ? null : round(array_sum($waits) / count($waits), 1),
                'longestWaitMinutes' => $longest === null ? null : $longest['waitMinutes'],
                'longestWaitPatient' => $longest === null ? null : $longest['patientName'],
            ];
        }

        return Inertia::render('ServiceQueues/Index', [
            'asOf' => $board?->asOf,
            'totals' => $totals,
            'queues' => $queues,
            'waitingEntries' => $waitingEntries,
            'calledEntries' => $calledEntries,
            'inServiceEntries' => $inServiceEntries,
            'onHoldEntries' => $onHoldEntries,
            'recallableEntries' => $recallableEntries,
            'transferQueues' => $this->presentQueueOptions($facilityId),
            'queueRoomAssignments' => $this->presentQueueRoomAssignments($facilityId),
            'myQueueRoomAssignments' => $this->presentMyQueueRoomAssignments($facilityId, (string) $user->id),
            // Room options are narrowed by the ticket's explicit queue assignment.
            'rooms' => $this->presentRooms($facilityId, $organizationApi),
        ]);
    }
}
