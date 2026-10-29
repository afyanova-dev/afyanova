<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Application\Query;

use App\Platform\Queueing\Contracts\DTO\QueueEntryDto;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Model\QueueEntry;

final class GetOpenTicketEntriesHandler
{
    /**
     * Open ticket entries grouped for board rendering: waiting, called,
     * in service, on hold. Oldest first within groups, bounded; not a
     * substitute for paginated worklists.
     *
     * @return array{waiting: list<QueueEntryDto>, called: list<QueueEntryDto>, inService: list<QueueEntryDto>, onHold: list<QueueEntryDto>}
     */
    public function __invoke(GetOpenTicketEntries $query): array
    {
        $groups = ['waiting' => [], 'called' => [], 'inService' => [], 'onHold' => []];

        $entries = QueueEntry::query()
            ->where('facility_id', $query->facilityId)
            ->whereIn('status', [
                QueueStatus::WAITING->value,
                QueueStatus::CALLED->value,
                QueueStatus::IN_SERVICE->value,
                QueueStatus::HOLD->value,
            ])
            ->orderBy('created_at')
            ->limit(max(1, $query->limit))
            ->get();

        foreach ($entries as $entry) {
            $groups[match ($entry->status) {
                QueueStatus::IN_SERVICE => 'inService',
                QueueStatus::CALLED => 'called',
                QueueStatus::HOLD => 'onHold',
                default => 'waiting',
            }][] = new QueueEntryDto(
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
            );
        }

        return $groups;
    }
}
