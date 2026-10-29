<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Presentation\Http\Request\UpdatePriorityRequest;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;

final class UpdateTicketPriorityController extends BaseQueueActionController
{
    public function __invoke(
        UpdatePriorityRequest $request,
        TenantContext $context,
        QueueingApi $queueingApi,
        string $entry,
    ): RedirectResponse {
        $user = $this->actor($request);
        $facilityId = $this->facilityId($context);

        $ticket = $this->entry($facilityId, $entry);

        $updated = $queueingApi->updatePriority(
            $ticket->id,
            strtoupper(trim((string) $request->input('priority'))),
            "service-queues:{$user->id}",
        );

        if ($updated === null) {
            return $this->fail('priority', 'This ticket has no active queue entry to update.');
        }

        return $this->done($request, "Ticket {$updated->ticketNumber} priority set to {$updated->priority}.");
    }
}
