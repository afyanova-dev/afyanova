<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Platform\Queueing\Contracts\QueueingApi;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CompleteTicketServiceController extends BaseQueueActionController
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
        $ticket = $this->entry($facilityId, $entry);

        $this->requireRoomAssignment($user, $ticket);

        try {
            $completed = $queueingApi->completeService($ticket->id);
        } catch (\Throwable $e) {
            return $this->domainError($e, 'complete', 'Service could not be completed', ['entry_id' => $ticket->id]);
        }

        return $this->done($request, "Ticket {$completed->ticketNumber} completed.");
    }
}
