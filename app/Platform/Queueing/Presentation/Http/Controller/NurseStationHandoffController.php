<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Domains\Clinical\Application\Query\GetEncounterTriageAssessments;
use App\Domains\Clinical\Contracts\Dto\TriageAssessmentSummary;
use App\Platform\Queueing\Contracts\DTO\QueueEntryDto;
use App\Platform\Queueing\Contracts\DTO\TransferQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Platform\Queueing\Presentation\Http\Request\NurseStationHandoffRequest;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NurseStationHandoffController extends BaseQueueActionController
{
    public function __invoke(
        NurseStationHandoffRequest $request,
        TenantContext $context,
        QueryBus $queryBus,
        QueueingApi $queueingApi,
        string $entry,
    ): RedirectResponse {
        $user = $this->actor($request);
        $facilityId = $this->facilityId($context);
        $ticket = QueueEntry::query()
            ->with('queue:id,service_capability')
            ->where('facility_id', $facilityId)
            ->whereIn('status', [
                QueueStatus::WAITING->value,
                QueueStatus::CALLED->value,
                QueueStatus::IN_SERVICE->value,
            ])
            ->findOrFail($entry);
        abort_unless(
            $ticket->queue?->service_capability === ServiceCapability::TRIAGE->value,
            404,
        );
        if ($ticket->status !== QueueStatus::IN_SERVICE) {
            return $this->fail('handoff_error', 'Start triage service after confirming the patient is present before handing off.');
        }

        $destination = Queue::query()
            ->where('facility_id', $facilityId)
            ->where('service_capability', ServiceCapability::CONSULTATION->value)
            ->where('is_active', true)
            ->where('operating_status', '!=', 'CLOSED')
            ->findOrFail((string) $request->validated('destination_queue_id'));

        /** @var list<TriageAssessmentSummary> $assessments */
        $assessments = $queryBus->dispatch(new GetEncounterTriageAssessments($ticket->encounter_id, currentOnly: true));
        if ($assessments === []) {
            return $this->fail('handoff_error', 'Record a triage assessment before sending this patient to consultation.');
        }

        try {
            $moved = DB::transaction(function () use ($ticket, $destination, $request, $queueingApi, $assessments): QueueEntryDto {
                $moved = $queueingApi->transfer(new TransferQueueDto(
                    entryId: $ticket->id,
                    destinationQueueId: $destination->id,
                    reason: trim((string) $request->validated('reason')),
                ));
                $updated = $queueingApi->updatePriority(
                    $moved->id,
                    $assessments[0]->triagePriority,
                    "TriageAssessment:{$assessments[0]->triageAssessmentId}",
                );
                if ($updated === null) {
                    throw new RuntimeException('The consultation queue ticket could not be updated with the triage priority.');
                }

                return $updated;
            });
        } catch (\Throwable $exception) {
            return $this->domainError($exception, 'handoff_error', 'nurse_station_handoff', [
                'entry_id' => $ticket->id,
                'destination_queue_id' => $destination->id,
                'actor_id' => (string) $user->getAuthIdentifier(),
            ]);
        }

        return redirect()->route('nurse-station.index')
            ->with('status', "Patient sent to {$destination->name} (ticket {$moved->ticketNumber}).");
    }
}
