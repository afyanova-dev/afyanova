<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Domains\Clinical\Contracts\ClinicalApi;
use App\Domains\Patient\Contracts\PatientApi;
use App\Models\User;
use App\Platform\Queueing\Application\Query\GetFacilityFlowBoardQuery;
use App\Platform\Queueing\Contracts\DTO\FacilityFlowBoardDto;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        ClinicalApi $clinicalApi,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');
        abort_unless($user->can('queue.ticket.view'), 403, 'You do not have access to service queues.');

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

        $procedureRouting = null;
        if ($user->can('queue.definition.manage') && $user->can('clinical.procedures.read')) {
            $definitions = $clinicalApi->getProcedureDefinitions();
            $mappings = DB::table('qms_procedure_queue_mappings')
                ->join('qms_queues', 'qms_queues.id', '=', 'qms_procedure_queue_mappings.queue_id')
                ->where('qms_procedure_queue_mappings.facility_id', $facilityId)
                ->where('qms_procedure_queue_mappings.tenant_id', $context->tenantId())
                ->where('qms_queues.service_capability', 'PROCEDURE')
                ->pluck('qms_procedure_queue_mappings.queue_id', 'qms_procedure_queue_mappings.procedure_definition_id');
            $procedureQueues = Queue::query()
                ->where('facility_id', $facilityId)
                ->where('service_capability', 'PROCEDURE')
                ->where('is_active', true)
                ->whereExists(function ($query) use ($facilityId): void {
                    $query->selectRaw('1')
                        ->from('qms_queue_rooms')
                        ->join('org_rooms', 'org_rooms.id', '=', 'qms_queue_rooms.room_id')
                        ->whereColumn('qms_queue_rooms.queue_id', 'qms_queues.id')
                        ->where('qms_queue_rooms.facility_id', $facilityId)
                        ->where('org_rooms.status', 'ACTIVE');
                })
                ->orderBy('name')
                ->get(['id', 'name', 'code']);

            $procedureRouting = [
                'definitions' => array_map(static fn ($definition): array => [
                    'definitionId' => $definition->definitionId,
                    'code' => $definition->code,
                    'name' => $definition->name,
                    'queueId' => isset($mappings[$definition->definitionId])
                        ? (string) $mappings[$definition->definitionId]
                        : '',
                ], $definitions),
                'queues' => $procedureQueues->map(static fn (Queue $queue): array => [
                    'queueId' => (string) $queue->id,
                    'queue' => $queue->name,
                    'code' => $queue->code,
                ])->values()->all(),
            ];
        }

        $procedureProtocolGovernance = null;
        if ($user->can('clinical.procedures.protocol_manage') && $user->can('clinical.procedures.read')) {
            $definitions = $clinicalApi->getProcedureDefinitions();
            $approvals = $clinicalApi->getDirectProcedureProtocolApprovals($facilityId);
            $procedureProtocolGovernance = [
                'definitions' => array_map(static fn ($definition): array => [
                    'definitionId' => $definition->definitionId,
                    'code' => $definition->code,
                    'name' => $definition->name,
                    'version' => $definition->version,
                ], $definitions),
                'approvals' => array_map(static fn ($approval): array => [
                    'approvalId' => $approval->approvalId,
                    'definitionId' => $approval->definitionId,
                    'definitionVersion' => $approval->definitionVersion,
                    'protocolReference' => $approval->protocolReference,
                    'protocolVersion' => $approval->protocolVersion,
                    'approvedByUserId' => $approval->approvedByUserId,
                    'approvedAt' => $approval->approvedAt,
                ], $approvals),
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
            'facilityStaff' => $user->can('queue.definition.manage')
                ? $this->presentFacilityStaff($context->tenantId(), $facilityId)
                : [],
            'queueRoomStaffAssignments' => $user->can('queue.definition.manage')
                ? $this->presentQueueRoomStaffAssignments($facilityId)
                : [],
            'procedureRouting' => $procedureRouting,
            'procedureProtocolGovernance' => $procedureProtocolGovernance,
            // Room options are narrowed by the ticket's explicit queue assignment.
            'rooms' => $this->presentRooms($facilityId),
        ]);
    }
}
