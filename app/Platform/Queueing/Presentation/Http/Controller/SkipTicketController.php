<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Platform\Queueing\Contracts\QueueingApi;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SkipTicketController extends BaseQueueActionController
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

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $ticket = $this->entry($facilityId, $entry);

        try {
            $skipped = $queueingApi->skip($ticket->id, $validated['reason'] ?? null);
        } catch (\Throwable $e) {
            return $this->domainError($e, 'skip', 'Ticket could not be skipped', ['entry_id' => $ticket->id]);
        }

        return $this->done($request, "Ticket {$skipped->ticketNumber} skipped.");
    }
}
