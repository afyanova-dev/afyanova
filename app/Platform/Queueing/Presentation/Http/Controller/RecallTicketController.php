<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Domains\Organization\Domain\Model\Room;
use App\Platform\Queueing\Contracts\DTO\RecallTicketDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Presentation\Http\Request\RecallTicketRequest;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

final class RecallTicketController extends BaseQueueActionController
{
    public function __invoke(
        RecallTicketRequest $request,
        TenantContext $context,
        QueueingApi $queueingApi,
        string $entry,
    ): RedirectResponse {
        $user = $this->actor($request);
        $facilityId = $this->facilityId($context);

        $ticket = $this->entry($facilityId, $entry);
        $roomId = $ticket->called_room_id;
        if ($request->filled('room')) {
            $roomId = (string) $request->input('room');
        }

        if ($roomId === null) {
            return $this->fail('room', 'Select a room assigned to this queue.');
        }

        /** @var Room */
        $room = Room::query()
            ->where('facility_id', $facilityId)
            ->where('status', 'ACTIVE')
            ->findOrFail($roomId);

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
            $recalled = $queueingApi->recall(new RecallTicketDto(
                entryId: $ticket->id,
                roomId: $room->id,
                staffId: null,
            ));
        } catch (\Throwable $e) {
            return $this->domainError($e, 'recall', 'Queue recall failed', ['entry_id' => $ticket->id]);
        }

        return $this->done($request, "Ticket {$recalled->ticketNumber} recalled.");
    }
}
