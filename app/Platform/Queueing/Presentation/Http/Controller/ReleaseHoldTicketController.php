<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Presentation\Http\Request\ReleaseHoldRequest;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;

final class ReleaseHoldTicketController extends BaseQueueActionController
{
    public function __invoke(
        ReleaseHoldRequest $request,
        TenantContext $context,
        QueueingApi $queueingApi,
        string $entry,
    ): RedirectResponse {
        $this->actor($request);
        $facilityId = $this->facilityId($context);

        $ticket = $this->entry($facilityId, $entry);

        try {
            $released = $queueingApi->releaseHold(
                $ticket->id,
                trim((string) $request->input('clearance_provenance')),
            );
        } catch (\Throwable $e) {
            return $this->domainError($e, 'release', 'Hold could not be released', ['entry_id' => $ticket->id]);
        }

        return $this->done($request, "Hold released for ticket {$released->ticketNumber}.");
    }
}
