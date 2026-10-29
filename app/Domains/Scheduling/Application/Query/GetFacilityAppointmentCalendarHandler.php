<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use Illuminate\Support\Facades\DB;

final class GetFacilityAppointmentCalendarHandler
{
    /**
     * Per-day appointment counts with a per-service breakdown for a date
     * range, powering the month workload grid. Cancelled appointments free
     * their slot, so they are excluded from workload.
     *
     * @return list<array{date: string, total: int, services: list<array{type: string, count: int}>}>
     */
    public function __invoke(GetFacilityAppointmentCalendarQuery $query): array
    {
        $rows = DB::table('sch_appointments as a')
            ->join('sch_appointment_types as t', 't.id', '=', 'a.appointment_type_id')
            ->where('a.facility_id', $query->facilityId)
            ->where('a.scheduled_start', '>=', $query->start->startOfDay())
            ->where('a.scheduled_start', '<', $query->end->addDay()->startOfDay())
            ->whereNotIn('a.status', ['CANCELLED'])
            ->groupByRaw("date_trunc('day', a.scheduled_start)::date, t.name")
            ->selectRaw("date_trunc('day', a.scheduled_start)::date as day, t.name as type, count(*) as total")
            ->orderBy('day')
            ->get();

        /** @var array<string, array{date: string, total: int, services: array<string, int>}> $days */
        $days = [];
        foreach ($rows as $row) {
            $day = (string) $row->day;
            $days[$day] ??= ['date' => $day, 'total' => 0, 'services' => []];
            $days[$day]['total'] += (int) $row->total;
            $days[$day]['services'][(string) $row->type] = (int) $row->total;
        }

        return array_values(array_map(static fn (array $day): array => [
            'date' => $day['date'],
            'total' => $day['total'],
            'services' => array_map(
                static fn (string $type, int $count): array => ['type' => $type, 'count' => $count],
                array_keys($day['services']),
                array_values($day['services']),
            ),
        ], $days));
    }
}
