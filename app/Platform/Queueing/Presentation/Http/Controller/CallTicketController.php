<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Domains\Organization\Contracts\OrganizationApi;
use App\Platform\Queueing\Contracts\DTO\CallTicketDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Presentation\Http\Request\CallTicketRequest;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final class CallTicketController extends BaseQueueActionController
{
    public function __invoke(
        CallTicketRequest $request,
        TenantContext $context,
        QueueingApi $queueingApi,
        OrganizationApi $organizationApi,
        string $entry,
    ): RedirectResponse {
        $user = $this->actor($request);
        $facilityId = $this->facilityId($context);

        $ticket = $this->entry($facilityId, $entry);
        $roomId = $request->input('room');
        $room = null;
        if (is_string($roomId) && $roomId !== '') {
            $room = $organizationApi->activeRoomForFacility($facilityId, $roomId);
            abort_unless($room !== null, 404);

            $roomIsAssigned = DB::table('qms_queue_rooms')
                ->where('tenant_id', $ticket->tenant_id)
                ->where('facility_id', $facilityId)
                ->where('queue_id', $ticket->queue_id)
                ->where('room_id', $room->id)
                ->exists();
            if (! $roomIsAssigned) {
                return $this->fail('room', 'Select a service location assigned to this queue.');
            }

            $this->requireRoomAssignment($user, $ticket, $room->id);
        } elseif ($this->hasRoomStaffRestrictions($ticket)) {
            return $this->fail('room', 'Select a service location to apply its staff access restrictions.');
        }

        try {
            $called = $queueingApi->call(new CallTicketDto(
                entryId: $ticket->id,
                roomId: $room?->id,
                staffId: null,
            ));
        } catch (\Throwable $e) {
            return $this->domainError($e, 'call', 'Queue call failed', ['entry_id' => $ticket->id]);
        }

        $destination = $room === null ? 'in the queue' : "to {$room->name}";

        return $this->done($request, "Ticket {$called->ticketNumber} called {$destination}.");
    }
}
