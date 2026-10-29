<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Presentation\Http\Request\HoldTicketRequest;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;

final class HoldTicketController extends BaseQueueActionController
{
    public function __invoke(
        HoldTicketRequest $request,
        TenantContext $context,
        QueueingApi $queueingApi,
        string $entry,
    ): RedirectResponse {
        $this->actor($request);
        $facilityId = $this->facilityId($context);

        $ticket = $this->entry($facilityId, $entry);

        try {
            $held = $queueingApi->placeHold(
                $ticket->id,
                strtoupper(trim((string) $request->input('hold_reason'))),
                trim((string) $request->input('hold_provenance')),
            );
        } catch (\Throwable $e) {
            return $this->domainError($e, 'hold', 'Hold could not be placed', ['entry_id' => $ticket->id]);
        }

        return $this->done($request, "Ticket {$held->ticketNumber} placed on hold.");
    }
}
