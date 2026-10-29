<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Models\User;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

abstract class BaseQueueActionController
{
    protected function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    protected function facilityId(TenantContext $context): string
    {
        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        return $facilityId;
    }

    /**
     * Load an entry scoped to the active facility. Anything else is a 404 —
     * a forged id from another facility must not be distinguishable.
     */
    protected function entry(string $facilityId, string $entryId): QueueEntry
    {
        /** @var QueueEntry */
        return QueueEntry::query()
            ->where('facility_id', $facilityId)
            ->findOrFail($entryId);
    }

    protected function queue(string $facilityId, string $queueId): Queue
    {
        /** @var Queue */
        return Queue::query()
            ->where('facility_id', $facilityId)
            ->findOrFail($queueId);
    }

    protected function requireRoomAssignment(User $user, QueueEntry $entry, ?string $roomId = null): void
    {
        $roomId ??= $entry->called_room_id;
        abort_unless(
            is_string($roomId)
                && DB::table('qms_queue_room_staff')
                    ->where('tenant_id', $entry->tenant_id)
                    ->where('facility_id', $entry->facility_id)
                    ->where('queue_id', $entry->queue_id)
                    ->where('room_id', $roomId)
                    ->where('user_id', $user->id)
                    ->exists(),
            403,
            'You are not assigned to this queue and room.',
        );
    }

    protected function done(Request $request, string $message): RedirectResponse
    {
        if ($request->filled('return_to')) {
            $returnTo = (string) $request->input('return_to');
            if (str_starts_with($returnTo, '/') && ! str_starts_with($returnTo, '//')) {
                return redirect($returnTo)->with('status', $message);
            }
        }

        return redirect()->route('service-queues.index')->with('status', $message);
    }

    protected function fail(string $key, string $message): RedirectResponse
    {
        return back()->withErrors([$key => $message]);
    }

    /**
     * Domain errors carry staff-readable messages (e.g. illegal transitions);
     * anything else is logged and replaced with a generic message.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function domainError(\Throwable $e, string $key, string $context, array $extra = []): RedirectResponse
    {
        if ($e instanceof \DomainException) {
            return $this->fail($key, $e->getMessage());
        }

        Log::warning($context, ['error' => $e->getMessage()] + $extra);

        return $this->fail($key, 'The action could not be completed. Please try again.');
    }
}
