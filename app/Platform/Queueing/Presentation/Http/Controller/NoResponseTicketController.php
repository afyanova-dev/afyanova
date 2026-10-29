<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Platform\Queueing\Contracts\QueueingApi;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class NoResponseTicketController extends BaseQueueActionController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        QueueingApi $queueingApi,
        string $entry,
    ): RedirectResponse {
        $user = $this->actor($request);
        abort_unless($user->can('queue.ticket.skip') || $user->can('queue.ticket.call'), 403);
        $facilityId = $this->facilityId($context);

        $ticket = $this->entry($facilityId, $entry);

        try {
            $updated = $queueingApi->recordNoResponse($ticket->id);
        } catch (\Throwable $e) {
            return $this->domainError($e, 'no_response', 'No-response could not be recorded', ['entry_id' => $ticket->id]);
        }

        return $this->done($request, "Ticket {$updated->ticketNumber} marked as no response.");
    }
}
