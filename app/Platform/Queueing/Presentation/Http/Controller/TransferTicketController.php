<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Platform\Queueing\Contracts\DTO\TransferQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Presentation\Http\Request\TransferTicketRequest;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;

final class TransferTicketController extends BaseQueueActionController
{
    public function __invoke(
        TransferTicketRequest $request,
        TenantContext $context,
        QueueingApi $queueingApi,
        string $entry,
    ): RedirectResponse {
        $this->actor($request);
        $facilityId = $this->facilityId($context);

        $ticket = $this->entry($facilityId, $entry);
        $destinationQueueId = (string) $request->input('destination_queue_id');
        $destination = $this->queue($facilityId, $destinationQueueId);

        if ($destination->id === $ticket->queue_id) {
            return $this->fail('destination_queue_id', 'The patient is already in the selected queue.');
        }

        try {
            $moved = $queueingApi->transfer(new TransferQueueDto(
                entryId: $ticket->id,
                destinationQueueId: $destination->id,
                reason: $request->filled('reason') ? trim((string) $request->input('reason')) : null,
            ));
        } catch (\Throwable $e) {
            return $this->domainError($e, 'transfer', 'The move could not be completed.', ['entry_id' => $ticket->id]);
        }

        return $this->done($request, "Patient moved to {$destination->name} (ticket {$moved->ticketNumber}).");
    }
}
