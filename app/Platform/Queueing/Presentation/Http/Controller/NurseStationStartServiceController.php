<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class NurseStationStartServiceController extends BaseQueueActionController
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
        abort_unless(
            $ticket->queue?->service_capability === ServiceCapability::TRIAGE->value,
            404,
        );
        if ($ticket->status !== QueueStatus::CALLED) {
            return $this->fail('start', 'Call this patient to a triage room before confirming their presence.');
        }
        $this->requireRoomAssignment($user, $ticket);

        try {
            $queueingApi->startService($ticket->id);
        } catch (\Throwable $exception) {
            return $this->domainError($exception, 'start', 'Nurse station triage service could not be started', [
                'entry_id' => $ticket->id,
            ]);
        }

        return redirect()->route('nurse-station.entries.show', ['entry' => $ticket->id]);
    }
}
