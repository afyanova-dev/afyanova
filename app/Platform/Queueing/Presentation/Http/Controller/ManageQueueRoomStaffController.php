<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Models\User;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ManageQueueRoomStaffController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        AuditLogger $audit,
        string $queue,
        string $room,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('queue.definition.manage'), 403, 'You cannot manage queue staff assignments.');

        $tenantId = $context->tenantId();
        $facilityId = $context->facilityId();
        abort_if($tenantId === '' || $facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $target = Queue::query()
            ->where('facility_id', $facilityId)
            ->findOrFail($queue);
        abort_unless(
            DB::table('qms_queue_rooms')
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('queue_id', $target->id)
                ->where('room_id', $room)
                ->exists(),
            404,
        );

        $staffUserIds = DB::table('idn_staff_identities')
            ->join('users', 'users.id', '=', 'idn_staff_identities.user_id')
            ->where('idn_staff_identities.tenant_id', $tenantId)
            ->where('idn_staff_identities.is_staff', true)
            ->where('users.status', 'ACTIVE')
            ->where(function ($query) use ($facilityId): void {
                $query->whereNull('idn_staff_identities.facility_id')
                    ->orWhere('idn_staff_identities.facility_id', $facilityId);
            })
            ->pluck('idn_staff_identities.user_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $validated = $request->validate([
            'staff_ids' => ['present', 'array'],
            'staff_ids.*' => ['required', 'uuid', 'distinct', Rule::in($staffUserIds)],
        ]);

        DB::transaction(function () use ($validated, $tenantId, $facilityId, $target, $room, $user, $audit): void {
            DB::table('qms_queue_room_staff')
                ->where('facility_id', $facilityId)
                ->where('queue_id', $target->id)
                ->where('room_id', $room)
                ->delete();

            $now = now();
            $assignments = array_map(
                static fn (string $staffId): array => [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'facility_id' => $facilityId,
                    'queue_id' => $target->id,
                    'room_id' => $room,
                    'user_id' => $staffId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $validated['staff_ids'],
            );

            if ($assignments !== []) {
                DB::table('qms_queue_room_staff')->insert($assignments);
            }

            $audit->recordFor(
                tenantId: $tenantId,
                action: 'queue.configuration.room_staff_updated',
                actorId: (string) $user->getKey(),
                facilityId: $facilityId,
                metadata: ['staff_count' => count($assignments)],
                targetType: 'room',
                targetId: $room,
            );
        });

        return redirect()
            ->route('service-queues.index')
            ->with('status', "Staff assignments updated for {$target->name}.");
    }
}
