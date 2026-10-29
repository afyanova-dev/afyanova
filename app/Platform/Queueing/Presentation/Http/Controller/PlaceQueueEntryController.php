<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Platform\Queueing\Contracts\DTO\PlaceInQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Presentation\Http\Request\PlaceQueueEntryRequest;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;

final class PlaceQueueEntryController extends BaseQueueActionController
{
    public function __invoke(
        PlaceQueueEntryRequest $request,
        TenantContext $context,
        QueueingApi $queueingApi,
    ): RedirectResponse {
        $this->actor($request);
        $facilityId = $this->facilityId($context);

        $queueId = (string) $request->input('queue_id');
        $queue = $this->queue($facilityId, $queueId);
        $capability = $queue->service_capability === null
            ? null
            : ServiceCapability::tryFrom($queue->service_capability);
        if ($capability?->requiresOrderProvenance()) {
            return $this->fail(
                'place',
                'This service queue accepts tickets only from its authorized order workflow.',
            );
        }

        try {
            $entry = $queueingApi->place(new PlaceInQueueDto(
                encounterId: (string) $request->input('encounter_id'),
                patientId: (string) $request->input('patient_id'),
                queueId: $queue->id,
                priority: strtoupper((string) $request->input('priority', 'ROUTINE')),
                sourceOrderRef: $request->filled('source_order_ref') ? trim((string) $request->input('source_order_ref')) : null,
                orderType: $request->filled('order_type') ? strtoupper(trim((string) $request->input('order_type'))) : null,
            ));
        } catch (\Throwable $e) {
            return $this->domainError($e, 'place', 'Queue placement failed', ['queue_id' => $queueId]);
        }

        return $this->done($request, "Ticket {$entry->ticketNumber} placed in {$queue->name}.");
    }
}
