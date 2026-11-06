<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Models\User;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ManageQueueRoomsController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        AuditLogger $audit,
        string $queue,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('queue.definition.manage'), 403, 'You cannot manage queue routing.');

        $tenantId = $context->tenantId();
        $facilityId = $context->facilityId();
        abort_if($tenantId === '' || $facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $target = Queue::query()
            ->where('facility_id', $facilityId)
            ->findOrFail($queue);

        $validated = $request->validate([
            'room_ids' => ['present', 'array'],
            'room_ids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('org_rooms', 'id')->where(
                    fn ($query) => $query
                        ->where('tenant_id', $tenantId)
                        ->where('facility_id', $facilityId)
                        ->where('status', 'ACTIVE'),
                ),
            ],
        ]);

        DB::transaction(function () use ($validated, $target, $tenantId, $facilityId, $user, $audit): void {
            if ((DB::table('qms_queue_entries')
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('queue_id', $target->id))
                ->whereIn('status', [
                    QueueStatus::CALLED->value,
                    QueueStatus::IN_SERVICE->value,
                    QueueStatus::NO_RESPONSE->value,
                    QueueStatus::SKIPPED->value,
                ])
                ->whereNotNull('called_room_id')
                ->whereNotIn('called_room_id', $validated['room_ids'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'room_ids' => 'Keep the called room assigned until its outstanding tickets are resolved.',
                ]);
            }

            $staffAssignments = DB::table('qms_queue_room_staff')
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('queue_id', $target->id);
            if ($validated['room_ids'] !== []) {
                $staffAssignments->whereNotIn('room_id', $validated['room_ids']);
            }
            $staffAssignments->delete();

            DB::table('qms_queue_rooms')
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('queue_id', $target->id)
                ->delete();

            $now = now();
            $assignments = array_map(
                static fn (string $roomId): array => [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'facility_id' => $facilityId,
                    'queue_id' => $target->id,
                    'room_id' => $roomId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $validated['room_ids'],
            );

            if ($assignments !== []) {
                DB::table('qms_queue_rooms')->insert($assignments);
            }

            $audit->recordFor(
                tenantId: $tenantId,
                action: 'queue.configuration.rooms_updated',
                actorId: (string) $user->getKey(),
                facilityId: $facilityId,
                metadata: ['room_count' => count($assignments)],
                targetType: 'queue',
                targetId: (string) $target->getKey(),
            );
        });

        return redirect()
            ->route('service-queues.manage')
            ->with('status', "Room routing updated for {$target->name}.");
    }
}
