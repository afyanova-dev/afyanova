<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Query;

use App\Platform\Queueing\Contracts\DTO\QueueEntryDto;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Model\QueueEntry;

final class GetFacilityWaitingEntriesHandler
{
    /**
     * Open waiting entries across a facility, oldest first, for monitoring
     * boards. Bounded; not a substitute for paginated worklists.
     *
     * @return list<QueueEntryDto>
     */
    public function __invoke(GetFacilityWaitingEntriesQuery $query): array
    {
        return QueueEntry::query()
            ->where('facility_id', $query->facilityId)
            ->where('status', QueueStatus::WAITING->value)
            ->orderBy('created_at')
            ->limit(max(1, $query->limit))
            ->get()
            ->map(static fn (QueueEntry $entry): QueueEntryDto => new QueueEntryDto(
                id: $entry->id,
                tenantId: $entry->tenant_id,
                facilityId: $entry->facility_id,
                queueId: $entry->queue_id,
                encounterId: $entry->encounter_id,
                patientId: $entry->patient_id,
                ticketNumber: $entry->ticket_number,
                priority: $entry->priority->value,
                status: $entry->status->value,
                holdReason: $entry->hold_reason?->value,
                holdProvenance: $entry->hold_provenance,
                clearanceProvenance: $entry->clearance_provenance,
                sourceOrderRef: $entry->source_order_ref,
                orderType: $entry->order_type?->value,
                calledAt: $entry->called_at,
                calledRoomId: $entry->called_room_id,
                calledStaffId: $entry->called_staff_id,
                serviceStartedAt: $entry->service_started_at,
                completedAt: $entry->completed_at,
                callAttempts: $entry->call_attempts,
                createdAt: $entry->created_at,
            ))
            ->all();
    }
}
