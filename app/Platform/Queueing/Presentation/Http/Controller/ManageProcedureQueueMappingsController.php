<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Domains\Clinical\Contracts\ClinicalApi;
use App\Models\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ManageProcedureQueueMappingsController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        ClinicalApi $clinicalApi,
        AuditLogger $audit,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(
            $user->can('queue.definition.manage') && $user->can('clinical.procedures.read'),
            403,
            'You cannot manage procedure queue routing.',
        );

        $tenantId = $context->tenantId();
        $facilityId = $context->facilityId();
        abort_if($tenantId === '' || $facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $definitions = $clinicalApi->getProcedureDefinitions();
        $definitionIds = array_fill_keys(array_map(
            static fn ($definition): string => $definition->definitionId,
            $definitions,
        ), true);

        $validated = $request->validate([
            'mappings' => ['required', 'array'],
            'mappings.*.definition_id' => ['required', 'uuid', 'distinct'],
            'mappings.*.queue_id' => [
                'nullable',
                'uuid',
                Rule::exists('qms_queues', 'id')->where(
                    fn ($query) => $query
                        ->where('tenant_id', $tenantId)
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
                        }),
                ),
            ],
        ]);

        foreach ($validated['mappings'] as $mapping) {
            if (! isset($definitionIds[$mapping['definition_id']])) {
                throw ValidationException::withMessages([
                    'mappings' => 'The procedure catalog changed. Reload the page and try again.',
                ]);
            }
        }

        DB::transaction(function () use ($validated, $tenantId, $facilityId, $user, $audit): void {
            DB::table('qms_procedure_queue_mappings')
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->delete();

            $now = now();
            $rows = [];
            foreach ($validated['mappings'] as $mapping) {
                $queueId = $mapping['queue_id'] ?? null;
                if ($queueId === null || $queueId === '') {
                    continue;
                }

                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'facility_id' => $facilityId,
                    'procedure_definition_id' => $mapping['definition_id'],
                    'queue_id' => $queueId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('qms_procedure_queue_mappings')->insert($rows);
            }

            $audit->recordFor(
                tenantId: $tenantId,
                action: 'queue.configuration.procedure_routing_updated',
                actorId: (string) $user->getKey(),
                facilityId: $facilityId,
                metadata: ['mapping_count' => count($rows)],
                targetType: 'facility',
                targetId: $facilityId,
            );
        });

        return redirect()
            ->route('service-queues.index')
            ->with('status', 'Procedure queue routing updated.');
    }
}
