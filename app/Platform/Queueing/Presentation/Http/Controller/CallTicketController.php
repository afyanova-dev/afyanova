<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Domains\Organization\Domain\Model\Room;
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
        string $entry,
    ): RedirectResponse {
        $user = $this->actor($request);
        $facilityId = $this->facilityId($context);

        $ticket = $this->entry($facilityId, $entry);
        /** @var Room */
        $room = Room::query()
            ->where('facility_id', $facilityId)
            ->where('status', 'ACTIVE')
            ->findOrFail($request->input('room'));

        $roomIsAssigned = DB::table('qms_queue_rooms')
            ->where('facility_id', $facilityId)
            ->where('queue_id', $ticket->queue_id)
            ->where('room_id', $room->id)
            ->exists();
        if (! $roomIsAssigned) {
            return $this->fail('room', 'Select a room assigned to this queue.');
        }
        $this->requireRoomAssignment($user, $ticket, (string) $room->id);

        try {
            $called = $queueingApi->call(new CallTicketDto(
                entryId: $ticket->id,
                roomId: $room->id,
                staffId: null,
            ));
        } catch (\Throwable $e) {
            return $this->domainError($e, 'call', 'Queue call failed', ['entry_id' => $ticket->id]);
        }

        return $this->done($request, "Ticket {$called->ticketNumber} called to {$room->name}.");
    }
}
