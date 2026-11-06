<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Domains\Clinical\Contracts\ClinicalApi;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Models\User;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class ServiceQueueManagementController
{
    use PresentsQueueEntries;

    public function __invoke(
        Request $request,
        TenantContext $context,
        ClinicalApi $clinicalApi,
        OrganizationApi $organizationApi,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');
        abort_unless($user->can('queue.definition.manage'), 403, 'You do not have access to queue configuration.');

        $queueDefinitions = Queue::query()
            ->where('facility_id', $facilityId)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'department_id', 'service_capability', 'ticket_prefix', 'is_active'])
            ->map(static fn (Queue $queue): array => [
                'id' => (string) $queue->id,
                'code' => $queue->code,
                'name' => $queue->name,
                'departmentId' => $queue->department_id,
                'serviceCapability' => $queue->service_capability,
                'ticketPrefix' => $queue->ticket_prefix,
                'isActive' => $queue->is_active,
            ])
            ->all();

        $queueDepartments = DB::table('org_departments')
            ->where('tenant_id', $context->tenantId())
            ->where('facility_id', $facilityId)
            ->orderBy('name')
            ->get(['id', 'name', 'status'])
            ->map(static fn (object $department): array => [
                'id' => (string) $department->id,
                'name' => (string) $department->name,
                'isActive' => $department->status === 'ACTIVE',
            ])
            ->all();

        $procedureRouting = null;
        if ($user->can('clinical.procedures.read')) {
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

        return Inertia::render('ServiceQueues/Manage', [
            'queueDefinitions' => $queueDefinitions,
            'queueDepartments' => $queueDepartments,
            'serviceCapabilities' => array_map(
                static fn (ServiceCapability $capability): string => $capability->value,
                ServiceCapability::cases(),
            ),
            'rooms' => $this->presentRooms($facilityId, $organizationApi),
            'queueRoomAssignments' => $this->presentQueueRoomAssignments($facilityId),
            'facilityStaff' => $this->presentFacilityStaff($context->tenantId(), $facilityId),
            'queueRoomStaffAssignments' => $this->presentQueueRoomStaffAssignments($facilityId),
            'procedureRouting' => $procedureRouting,
            'procedureProtocolGovernance' => $procedureProtocolGovernance,
        ]);
    }
}
