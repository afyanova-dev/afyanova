<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Presentation\Http\Presenter;

use App\Domains\CareCoordination\Contracts\CareCoordinationApi;
use App\Domains\CareCoordination\Contracts\Dto\ReferralSummary;
use App\Domains\Clinical\Contracts\ClinicalApi;
use App\Domains\Clinical\Contracts\Dto\EncounterDocumentationStatus;
use App\Domains\Encounter\Application\Query\ListFacilityEncounters;
use App\Domains\Encounter\Contracts\Dto\EncounterSummary;
use App\Domains\Encounter\Presentation\Http\DTO\ReceptionAppointmentItem;
use App\Domains\Encounter\Presentation\Http\DTO\ReceptionDashboardProps;
use App\Domains\Encounter\Presentation\Http\DTO\ReceptionEncounterItem;
use App\Domains\Encounter\Presentation\Http\DTO\ReceptionMetrics;
use App\Domains\Encounter\Presentation\Http\DTO\ReceptionReferralItem;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Scheduling\Contracts\Dto\AppointmentSummary;
use App\Domains\Scheduling\Contracts\SchedulingApi;
use App\Platform\Queueing\Contracts\DTO\QueueDto;
use App\Platform\Queueing\Contracts\DTO\QueueEntryDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;

final readonly class ReceptionPresenter
{
    public function __construct(
        private QueryBus $queryBus,
        private SchedulingApi $schedulingApi,
        private PatientApi $patientApi,
        private QueueingApi $queueingApi,
        private CareCoordinationApi $careCoordinationApi,
        private ClinicalApi $clinicalApi,
    ) {}

    /**
     * @param  list<string>|null  $encounterStatuses
     */
    public function present(
        string $facilityId,
        CarbonImmutable $date,
        string $facilityTimeZone = 'Africa/Dar_es_Salaam',
        ?array $encounterStatuses = null,
        string $rosterFilter = '',
        int $limit = 100,
        bool $includeEncounters = true,
        bool $includeAppointments = true,
        bool $includePatientDetails = true,
        bool $includeTickets = true,
    ): ReceptionDashboardProps {
        $today = CarbonImmutable::now($facilityTimeZone)->startOfDay();

        // Chart-style section gating: denied sections compose empty with a
        // `restricted` flag instead of leaking rows the viewer may not see.
        $restricted = [];
        if (! $includeEncounters) {
            $restricted[] = 'encounters';
        }
        if (! $includeAppointments) {
            $restricted[] = 'appointments';
        }
        if (! $includeTickets) {
            $restricted[] = 'tickets';
        }

        // Metrics stay scoped to the selected facility day. Today's roster
        // additionally carries forward any still-open attendance from an
        // earlier day so reception can resolve patients who have not finished.
        /** @var list<EncounterSummary> $todayEncounters */
        $todayEncounters = [];
        if ($includeEncounters) {
            $todayEncounters = $this->queryBus->dispatch(new ListFacilityEncounters(
                facilityId: $facilityId,
                date: $date,
                statuses: null,
                limit: 200,
            ));
        }

        /** @var list<EncounterSummary> $encountersForMetrics */
        $encountersForMetrics = $todayEncounters;

        /** @var list<EncounterSummary> $encountersForRoster */
        $encountersForRoster = $todayEncounters;
        if ($includeEncounters && $date->toDateString() === $today->toDateString()) {
            $openEncounters = $this->queryBus->dispatch(new ListFacilityEncounters(
                facilityId: $facilityId,
                date: null,
                statuses: ['ARRIVED', 'ACTIVE'],
                limit: 500,
            ));
            $previouslyArrived = array_filter(
                $openEncounters,
                static fn (EncounterSummary $encounter): bool => $encounter->arrivedAt !== null
                    && CarbonImmutable::parse($encounter->arrivedAt)->lt($date->startOfDay()),
            );
            $encountersForRoster = array_values(array_merge($encountersForRoster, $previouslyArrived));
        }

        if ($encounterStatuses !== null) {
            $encountersForRoster = array_values(array_filter(
                $encountersForRoster,
                static fn (EncounterSummary $encounter): bool => in_array($encounter->status, $encounterStatuses, true),
            ));
        }

        // Chronologically sort roster descending by arrival / started time
        usort($encountersForRoster, static function (EncounterSummary $a, EncounterSummary $b): int {
            $timeA = $a->arrivedAt ?? $a->startedAt ?? '';
            $timeB = $b->arrivedAt ?? $b->startedAt ?? '';

            return strcmp($timeB, $timeA);
        });

        // 7-Day Navigation Strip (< SUN .. SAT >) matching Dashboard
        $weekStart = $date->startOfWeek(CarbonImmutable::SUNDAY);
        $weekEnd = $weekStart->addDays(6);
        // End month (and start year) print only when the week crosses
        // a month/year boundary — otherwise "September 27 - 3" misleads.
        if ($weekStart->isSameMonth($weekEnd)) {
            $weekLabel = sprintf(
                'Week of %s - %s, %s',
                $weekStart->format('F j'),
                $weekEnd->format('j'),
                $weekEnd->format('Y')
            );
        } elseif ($weekStart->isSameYear($weekEnd)) {
            $weekLabel = sprintf(
                'Week of %s - %s, %s',
                $weekStart->format('F j'),
                $weekEnd->format('F j'),
                $weekEnd->format('Y')
            );
        } else {
            $weekLabel = sprintf(
                'Week of %s, %s - %s, %s',
                $weekStart->format('F j'),
                $weekStart->format('Y'),
                $weekEnd->format('F j'),
                $weekEnd->format('Y')
            );
        }

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $dayDate = $weekStart->addDays($i);
            $days[] = [
                'dayOfWeek' => strtoupper($dayDate->format('D')),
                'date' => $dayDate->toDateString(),
                'label' => strtoupper($dayDate->format('D n/j')),
                'isToday' => $dayDate->isSameDay($today),
                'isSelected' => $dayDate->isSameDay($date),
            ];
        }

        /** @var list<AppointmentSummary> $appointments */
        $appointments = $includeAppointments
            ? $this->schedulingApi->getDailyFacilityAppointments($facilityId, $date)
            : [];

        // Type/resource display names resolve through the scheduling
        // contract — fixed cost regardless of row count, never per-appointment
        // lookups. Denied or failing resolution degrades names to null; the
        // board still renders.
        /** @var array<string, string> $typeNames */
        $typeNames = [];
        /** @var array<string, string> $resourceNames */
        $resourceNames = [];
        if ($includeAppointments) {
            try {
                $names = $this->schedulingApi->getSchedulingDisplayNames($facilityId);
                $typeNames = $names['types'];
                $resourceNames = $names['resources'];
            } catch (\Throwable) {
                // Names degrade to null; the board still renders.
            }
        }

        $referrals = $this->inboundReferrals($facilityId);

        $patientIds = [];
        foreach ($encountersForRoster as $encounter) {
            $patientIds[$encounter->patientId] = true;
        }
        foreach ($encountersForMetrics as $encounter) {
            $patientIds[$encounter->patientId] = true;
        }
        foreach ($appointments as $appointment) {
            $patientIds[$appointment->patientId] = true;
        }
        foreach ($referrals as $referral) {
            $patientIds[$referral->patientId] = true;
        }

        /** @var array<string, PatientSummary> $patients */
        $patients = $includePatientDetails
            ? $this->patientApi->getPatientSummaries(array_keys($patientIds))
            : [];

        $queueItems = [];
        try {
            /** @var list<QueueDto> $queues */
            $queues = $this->queueingApi->listFacilityQueues($facilityId, true);
            $queueItems = array_map(
                static fn (QueueDto $q): array => [
                    'id' => $q->id,
                    'departmentId' => $q->departmentId,
                    'name' => $q->name,
                    'code' => $q->code,
                    'serviceCapability' => $q->serviceCapability,
                    'roomNames' => $q->roomNames,
                ],
                $queues
            );
        } catch (\Throwable $exception) {
            Log::warning('Reception queue names could not be loaded', [
                'facility_id' => $facilityId,
                'error' => $exception->getMessage(),
            ]);
        }

        $queueNames = [];
        foreach ($queueItems as $queue) {
            $queueNames[$queue['id']] = $queue['name'];
        }

        /** @var array<string, QueueEntryDto> $ticketByEncounterId */
        $ticketByEncounterId = [];
        $inServicePatients = null;
        if ($includeTickets) {
            try {
                $openTickets = $this->queueingApi->getOpenTicketEntries($facilityId);
                foreach ($openTickets as $entries) {
                    foreach ($entries as $ticket) {
                        $ticketByEncounterId[$ticket->encounterId] = $ticket;
                    }
                }
                $inServicePatients = count(array_unique(array_map(
                    static fn (QueueEntryDto $ticket): string => $ticket->patientId,
                    array_filter(
                        $ticketByEncounterId,
                        static fn (QueueEntryDto $ticket): bool => $ticket->status === 'IN_SERVICE',
                    ),
                )));
            } catch (\Throwable $exception) {
                Log::warning('Reception queue ticket status could not be loaded', [
                    'facility_id' => $facilityId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        /** @var array<string, EncounterDocumentationStatus> $documentationByEncounterId */
        $documentationByEncounterId = [];
        if ($encountersForRoster !== []) {
            try {
                $statuses = $this->clinicalApi->getEncounterDocumentationStatuses(
                    $facilityId,
                    array_values(array_unique(array_map(
                        static fn (EncounterSummary $encounter): string => $encounter->encounterId,
                        $encountersForRoster,
                    ))),
                );
                foreach ($statuses as $status) {
                    $documentationByEncounterId[$status->encounterId] = $status;
                }
            } catch (\Throwable $exception) {
                Log::warning('Reception clinical documentation status could not be loaded', [
                    'facility_id' => $facilityId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $filter = trim($rosterFilter);

        $encounterItems = array_values(array_filter(
            array_map(
                static fn (EncounterSummary $e): ReceptionEncounterItem => ReceptionEncounterItem::fromSummary(
                    $e,
                    $patients[$e->patientId] ?? null,
                    $ticketByEncounterId[$e->encounterId]->status ?? null,
                    isset($ticketByEncounterId[$e->encounterId])
                        ? ($queueNames[$ticketByEncounterId[$e->encounterId]->queueId] ?? null)
                        : null,
                    $ticketByEncounterId[$e->encounterId]->ticketNumber ?? null,
                    $documentationByEncounterId[$e->encounterId]->vitalsRecorded ?? null,
                    $documentationByEncounterId[$e->encounterId]->triageRecorded ?? null,
                ),
                $encountersForRoster,
            ),
            fn (ReceptionEncounterItem $item): bool => $this->matchesFilter(
                $filter,
                $item->patient,
                $item->encounterNumber,
                $item->encounterType,
            ),
        ));

        $appointmentStatuses = $encounterStatuses === null
            ? null
            : array_map(
                static fn (string $status): string => match ($status) {
                    'BOOKED' => 'BOOKED',
                    'ARRIVED' => 'ARRIVED',
                    'ACTIVE' => 'IN_SERVICE',
                    'COMPLETED' => 'COMPLETED',
                    default => '',
                },
                $encounterStatuses,
            );

        $appointmentItems = array_values(array_filter(
            array_map(
                static fn (AppointmentSummary $a): ReceptionAppointmentItem => ReceptionAppointmentItem::fromSummary(
                    $a,
                    $patients[$a->patientId] ?? null,
                    $typeNames[$a->appointmentTypeId] ?? null,
                    $resourceNames[$a->resourceId] ?? null,
                ),
                $appointments,
            ),
            function (ReceptionAppointmentItem $item) use ($filter, $appointmentStatuses): bool {
                if ($appointmentStatuses !== null && ! in_array($item->status, $appointmentStatuses, true)) {
                    return false;
                }

                return $this->matchesFilter(
                    $filter,
                    $item->patient,
                    $item->appointmentNumber,
                    $item->reason ?? '',
                );
            },
        ));

        $referralItems = array_map(
            static fn (ReferralSummary $r): ReceptionReferralItem => ReceptionReferralItem::fromSummary($r, $patients[$r->patientId] ?? null),
            $referrals,
        );

        $metrics = $this->computeMetrics($encountersForMetrics, $appointments, $inServicePatients);

        $statusLabel = $encounterStatuses === null || $encounterStatuses === []
            ? 'ALL'
            : implode(',', $encounterStatuses);

        return new ReceptionDashboardProps(
            facilityId: $facilityId,
            date: $date->toDateString(),
            timezone: $facilityTimeZone,
            encounters: $encounterItems,
            appointments: $appointmentItems,
            metrics: $metrics,
            availableQueues: $queueItems,
            referrals: $referralItems,
            rosterStatus: $statusLabel,
            rosterFilter: $filter,
            weekLabel: $weekLabel,
            days: $days,
            restricted: $restricted,
        );
    }

    /**
     * @return list<ReferralSummary>
     */
    private function inboundReferrals(string $facilityId): array
    {
        try {
            return $this->careCoordinationApi->searchReferrals($facilityId);
        } catch (NotFound|AuthorizationException) {
            return [];
        }
    }

    private function matchesFilter(string $filter, ?PatientSummary $patient, string ...$haystacks): bool
    {
        if ($filter === '') {
            return true;
        }

        $needle = mb_strtolower($filter);
        if ($patient !== null) {
            if (str_contains(mb_strtolower($patient->displayName), $needle)
                || str_contains(mb_strtolower($patient->patientNumber), $needle)) {
                return true;
            }
        }

        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<EncounterSummary>  $encounters
     * @param  list<AppointmentSummary>  $appointments
     */
    private function computeMetrics(array $encounters, array $appointments, ?int $inServicePatients): ReceptionMetrics
    {
        $waitingEncounters = 0;
        $inServiceEncounters = 0;
        $activeAttendanceEncounters = 0;
        $completedEncounters = 0;

        foreach ($encounters as $e) {
            if ($e->status === 'ARRIVED') {
                $waitingEncounters++;
            } elseif ($e->status === 'ACTIVE') {
                $inServiceEncounters++;
            } elseif ($e->status === 'COMPLETED') {
                $completedEncounters++;
            }

            if (in_array($e->status, ['ARRIVED', 'ACTIVE'], true)) {
                $activeAttendanceEncounters++;
            }
        }

        $expectedAppointments = 0;
        $arrivedAppointments = 0;
        $inServiceAppointments = 0;
        $completedAppointments = 0;

        foreach ($appointments as $a) {
            if (in_array($a->status, ['BOOKED', 'CONFIRMED'], true)) {
                $expectedAppointments++;
            } elseif ($a->status === 'ARRIVED') {
                $arrivedAppointments++;
            } elseif ($a->status === 'IN_SERVICE') {
                $inServiceAppointments++;
            } elseif ($a->status === 'COMPLETED') {
                $completedAppointments++;
            }
        }

        return new ReceptionMetrics(
            totalEncounters: count($encounters),
            waitingEncounters: $waitingEncounters,
            inServiceEncounters: $inServiceEncounters,
            inServicePatients: $inServicePatients,
            activeAttendanceEncounters: $activeAttendanceEncounters,
            completedEncounters: $completedEncounters,
            totalAppointments: count($appointments),
            expectedAppointments: $expectedAppointments,
            arrivedAppointments: $arrivedAppointments,
            inServiceAppointments: $inServiceAppointments,
            completedAppointments: $completedAppointments,
        );
    }
}
