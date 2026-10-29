<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Presentation\Http\Controller;

use App\Domains\Organization\Domain\Model\Room;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

trait PresentsQueueEntries
{
    /**
     * Load open entries for a facility (optionally one queue) and present
     * them grouped the way the board renders: waiting, called, in service,
     * on hold, and recallable. Failures degrade to empty groups, never to a 500.
     *
     * @return array{
     *     waiting: list<array<string, mixed>>,
     *     called: list<array<string, mixed>>,
     *     inService: list<array<string, mixed>>,
     *     onHold: list<array<string, mixed>>,
     *     recallable: list<array<string, mixed>>
     * }
     */
    protected function presentQueueEntries(string $facilityId, ?string $queueId, PatientApi $patientApi): array
    {
        $groups = ['waiting' => [], 'called' => [], 'inService' => [], 'onHold' => [], 'recallable' => []];

        try {
            $query = QueueEntry::query()
                ->with('queue')
                ->where('facility_id', $facilityId)
                ->whereIn('status', [
                    QueueStatus::WAITING->value,
                    QueueStatus::CALLED->value,
                    QueueStatus::IN_SERVICE->value,
                    QueueStatus::HOLD->value,
                    QueueStatus::NO_RESPONSE->value,
                    QueueStatus::SKIPPED->value,
                ])
                ->orderBy('created_at')
                ->limit(200);

            if ($queueId !== null) {
                $query->where('queue_id', $queueId);
            }

            $entries = $query->get();

            // Patient names are decoration: if the lookup fails the board
            // still renders with fallback labels rather than empty groups.
            $patientIds = [];
            foreach ($entries->pluck('patient_id')->all() as $patientId) {
                if (is_string($patientId) && $patientId !== '') {
                    $patientIds[] = $patientId;
                }
            }
            $patientIds = array_values(array_unique($patientIds));

            try {
                $patients = $patientIds === [] ? [] : $patientApi->getPatientSummaries($patientIds);
            } catch (\Throwable) {
                $patients = [];
            }

            $now = CarbonImmutable::now();
            $mapEntry = static function (QueueEntry $entry) use ($patients, $now): array {
                $patientName = 'Unknown patient';
                $patientNumber = '—';
                $dateOfBirth = null;
                $sex = null;
                $summary = $patients[$entry->patient_id] ?? null;
                if ($summary instanceof PatientSummary) {
                    $patientName = $summary->displayName;
                    $patientNumber = $summary->patientNumber;
                    $dateOfBirth = $summary->dateOfBirth;
                    $sex = $summary->sex;
                }

                $queueName = '—';
                $queueCode = null;
                if ($entry->queue instanceof Queue) {
                    $queueName = $entry->queue->name;
                    $queueCode = $entry->queue->code;
                }

                return [
                    'id' => $entry->id,
                    'queueId' => $entry->queue_id,
                    'ticketNumber' => $entry->ticket_number,
                    'patientName' => $patientName,
                    'patientNumber' => $patientNumber,
                    'dateOfBirth' => $dateOfBirth,
                    'sex' => $sex,
                    'queue' => $queueName,
                    'queueCode' => $queueCode,
                    'serviceCapability' => $entry->queue instanceof Queue ? $entry->queue->service_capability : null,
                    'sourceOrderRef' => $entry->source_order_ref,
                    'orderType' => $entry->order_type?->value,
                    'priority' => $entry->priority->value,
                    'status' => $entry->status->value,
                    'holdReason' => $entry->hold_reason?->value,
                    'waitMinutes' => max(0, $now->diffInMinutes($entry->created_at, true)),
                    'arrivedAt' => $entry->created_at->toIso8601String(),
                    'calledRoomId' => $entry->called_room_id,
                ];
            };

            foreach ($entries as $entry) {
                $groups[match ($entry->status) {
                    QueueStatus::IN_SERVICE => 'inService',
                    QueueStatus::CALLED => 'called',
                    QueueStatus::HOLD => 'onHold',
                    QueueStatus::NO_RESPONSE, QueueStatus::SKIPPED => 'recallable',
                    default => 'waiting',
                }][] = $mapEntry($entry);
            }
        } catch (\Throwable) {
            // Degrade to empty groups; the page still renders.
        }

        return $groups;
    }

    /**
     * @return array<int, array{id: string, name: string, code: string}>
     */
    protected function presentRooms(string $facilityId): array
    {
        return Room::query()
            ->where('facility_id', $facilityId)
            ->where('status', 'ACTIVE')
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(static fn (Room $room): array => [
                'id' => $room->id,
                'name' => $room->name,
                'code' => $room->code,
            ])
            ->all();
    }

    /**
     * Every active queue in the facility, for transfer-destination pickers.
     *
     * @return array<int, array{queueId: string, queue: string}>
     */
    protected function presentQueueOptions(string $facilityId): array
    {
        return Queue::query()
            ->where('facility_id', $facilityId)
            ->where('is_active', true)
            ->where('operating_status', '!=', 'CLOSED')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Queue $queue): array => [
                'queueId' => $queue->id,
                'queue' => $queue->name,
            ])
            ->all();
    }

    /**
     * @return array<int, array{queueId: string, queue: string, code: string, roomIds: array<int, string>}>
     */
    protected function presentQueueRoomAssignments(string $facilityId): array
    {
        $assignments = DB::table('qms_queue_rooms')
            ->where('facility_id', $facilityId)
            ->orderBy('queue_id')
            ->orderBy('room_id')
            ->get(['queue_id', 'room_id'])
            ->groupBy('queue_id');

        return Queue::query()
            ->where('facility_id', $facilityId)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(static fn (Queue $queue): array => [
                'queueId' => $queue->id,
                'queue' => $queue->name,
                'code' => $queue->code,
                'roomIds' => $assignments->get($queue->id, collect())
                    ->pluck('room_id')
                    ->map(static fn ($roomId): string => (string) $roomId)
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return array<int, array{queueId: string, roomIds: array<int, string>}>
     */
    protected function presentMyQueueRoomAssignments(string $facilityId, string $userId): array
    {
        return DB::table('qms_queue_room_staff')
            ->where('facility_id', $facilityId)
            ->where('user_id', $userId)
            ->orderBy('queue_id')
            ->orderBy('room_id')
            ->get(['queue_id', 'room_id'])
            ->groupBy('queue_id')
            ->map(static fn ($assignments, string $queueId): array => [
                'queueId' => $queueId,
                'roomIds' => $assignments->pluck('room_id')
                    ->map(static fn ($roomId): string => (string) $roomId)
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{userId: string, name: string}>
     */
    protected function presentFacilityStaff(string $tenantId, string $facilityId): array
    {
        return DB::table('idn_staff_identities')
            ->join('users', 'users.id', '=', 'idn_staff_identities.user_id')
            ->where('idn_staff_identities.tenant_id', $tenantId)
            ->where('idn_staff_identities.is_staff', true)
            ->where('users.status', 'ACTIVE')
            ->where(function ($query) use ($facilityId): void {
                $query->whereNull('idn_staff_identities.facility_id')
                    ->orWhere('idn_staff_identities.facility_id', $facilityId);
            })
            ->orderBy('users.name')
            ->get(['idn_staff_identities.user_id', 'users.name'])
            ->map(static fn ($staff): array => [
                'userId' => (string) $staff->user_id,
                'name' => (string) $staff->name,
            ])
            ->all();
    }

    /**
     * @return array<int, array{queueId: string, roomId: string, userIds: array<int, string>}>
     */
    protected function presentQueueRoomStaffAssignments(string $facilityId): array
    {
        return DB::table('qms_queue_room_staff')
            ->where('facility_id', $facilityId)
            ->orderBy('queue_id')
            ->orderBy('room_id')
            ->orderBy('user_id')
            ->get(['queue_id', 'room_id', 'user_id'])
            ->groupBy(static fn ($assignment): string => "{$assignment->queue_id}:{$assignment->room_id}")
            ->map(static fn ($assignments): array => [
                'queueId' => (string) $assignments->first()->queue_id,
                'roomId' => (string) $assignments->first()->room_id,
                'userIds' => $assignments->pluck('user_id')
                    ->map(static fn ($userId): string => (string) $userId)
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
