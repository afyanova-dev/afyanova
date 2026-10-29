<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use App\Domains\HR\Contracts\Dto\AttendanceEventSummary;
use App\Domains\HR\Domain\AttendanceEventReader;
use App\Domains\HR\Domain\Exception\AttendanceEventNotFoundException;
use App\Domains\HR\Domain\Model\AttendanceEvent;
use Carbon\CarbonImmutable;

/**
 * Eloquent implementation of AttendanceEventReader (25-hr-and-workforce-domain.md §6, §10).
 */
final class EloquentAttendanceEventReader implements AttendanceEventReader
{
    public function summary(string $eventId): AttendanceEventSummary
    {
        /** @var AttendanceEvent|null $event */
        $event = AttendanceEvent::query()
            ->with(['staff.person'])
            ->whereKey($eventId)
            ->first();

        if ($event === null) {
            throw AttendanceEventNotFoundException::forId($eventId);
        }

        return $this->toSummary($event);
    }

    /**
     * @return list<AttendanceEventSummary>
     */
    public function forStaff(
        string $staffId,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?int $limit = null,
    ): array {
        $query = AttendanceEvent::query()
            ->with(['staff.person'])
            ->where('staff_id', $staffId)
            ->orderBy('occurred_at', 'desc');

        if ($from !== null) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('occurred_at', '<=', $to);
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        /** @var list<AttendanceEvent> $events */
        $events = $query->get()->all();

        return array_map(fn (AttendanceEvent $event): AttendanceEventSummary => $this->toSummary($event), $events);
    }

    /**
     * @return list<AttendanceEventSummary>
     */
    public function forFacility(
        string $facilityId,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        ?int $limit = null,
    ): array {
        $query = AttendanceEvent::query()
            ->with(['staff.person'])
            ->where('facility_id', $facilityId)
            ->orderBy('occurred_at', 'desc');

        if ($from !== null) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('occurred_at', '<=', $to);
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        /** @var list<AttendanceEvent> $events */
        $events = $query->get()->all();

        return array_map(fn (AttendanceEvent $event): AttendanceEventSummary => $this->toSummary($event), $events);
    }

    private function toSummary(AttendanceEvent $event): AttendanceEventSummary
    {
        $staffName = $event->staff?->person !== null
            ? trim($event->staff->person->first_name.' '.$event->staff->person->last_name)
            : null;

        return new AttendanceEventSummary(
            attendanceEventId: $event->id,
            tenantId: $event->tenant_id,
            facilityId: $event->facility_id,
            staffId: $event->staff_id,
            staffNumber: $event->staff?->staff_number,
            staffName: $staffName !== '' ? $staffName : null,
            source: $event->source->value,
            deviceRef: $event->device_ref,
            externalEventId: $event->external_event_id,
            direction: $event->direction->value,
            occurredAt: $event->occurred_at->toIso8601String(),
            ingestedAt: $event->ingested_at->toIso8601String(),
            rawPayload: $event->raw_payload,
            notes: $event->notes,
            version: $event->version,
        );
    }
}
