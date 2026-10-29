<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StartTicketServiceController extends BaseQueueActionController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        QueueingApi $queueingApi,
        string $entry,
    ): RedirectResponse {
        $user = $this->actor($request);
        abort_unless($user->can('queue.ticket.service'), 403);
        $facilityId = $this->facilityId($context);

        $ticket = QueueEntry::query()
            ->with('queue:id,service_capability')
            ->where('facility_id', $facilityId)
            ->findOrFail($entry);
        $this->requireRoomAssignment($user, $ticket);
        if ($ticket->queue?->service_capability === ServiceCapability::TRIAGE->value) {
            return $this->fail('start', 'Confirm the patient is present and start triage from Nurse Station.');
        }

        try {
            $started = $queueingApi->startService($ticket->id);
        } catch (\Throwable $e) {
            return $this->domainError($e, 'start', 'Service could not be started', ['entry_id' => $ticket->id]);
        }

        return $this->done($request, "Service started for ticket {$started->ticketNumber}.");
    }
}
