<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Domains\CareCoordination\Contracts\CareCoordinationApi;
use App\Domains\Encounter\Application\Query\ListFacilityEncounters;
use App\Domains\Encounter\Contracts\Dto\EncounterSummary;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Scheduling\Contracts\Dto\AppointmentSummary;
use App\Domains\Scheduling\Contracts\SchedulingApi;
use App\Models\User;
use App\Platform\Reporting\Contracts\ReportingApi;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Presenter for the shell-level 3-column Dsk Clinical Dashboard.
 *
 * Adapts content across the 3 columns (Inbox, Schedule, Network) based on the
 * authenticated user's effective permissions and persona in the active facility scope.
 */
final readonly class DashboardPresenter
{
    public function __construct(
        private QueryBus $queryBus,
        private SchedulingApi $schedulingApi,
        private PatientApi $patientApi,
        private CareCoordinationApi $careCoordinationApi,
        private ReportingApi $reportingApi,
    ) {}

    /**
     * @return array{
     *     inboxCategories: list<array{id: string, label: string, count: number, href: string}>,
     *     calloutNotice: array{text: string, actionLabel: string, href: string},
     *     days: list<array{dayOfWeek: string, date: string, label: string, isToday: bool, isSelected: bool}>,
     *     weekLabel: string,
     *     selectedDate: string,
     *     statusOptions: list<array{id: string, label: string, shortLabel: string}>,
     *     selectedStatus: string,
     *     providers: list<array{id: string, name: string}>,
     *     selectedProviderId: ?string,
     *     appointments: list<array{id: string, time: string, patientName: string, patientId?: ?string, chartNumber?: ?string, ageGender?: ?string, reason: string, providerName: ?string, status: string, room?: ?string, type?: string, href?: string}>,
     *     alert: ?array{id: string, title: string, headline: string, body: string, severity: string},
     *     messageGroups: list<array{date: string, items: list<array{id: string, title: string, isAlert?: bool, href?: string}>}>,
     *     kpis: ?array{encounters: array{total: int, admissions: int, discharges: int, emergencies: int}, lab: array{tests_completed: int, avg_tat_minutes: float}, financials?: array{total_billed: float, total_collected: float}, bedOccupancy?: array{total_beds: int, occupied_beds: int, rate_percent: float}, pharmacy?: array{total_prescriptions: int, total_active: int, total_cancelled: int}|null},
     *     asOf: ?string,
     * }
     */
    public function present(
        User $user,
        TenantContext $context,
        ScopeChecker $scope,
        ?string $dateInput = null,
        ?string $statusInput = null,
        ?string $providerId = null,
    ): array {
        $facilityId = $context->facilityId();
        $permissions = $scope->effectivePermissions($user, $facilityId);

        $today = CarbonImmutable::now();
        $selectedDate = $dateInput !== null && $dateInput !== ''
            ? CarbonImmutable::parse($dateInput)
            : $today;

        // ── Detect Operational Persona ──
        $isReceptionist = in_array('encounter.encounters.create', $permissions, true)
            && ! in_array('clinical.access', $permissions, true);

        $isClinician = in_array('clinical.access', $permissions, true)
            || in_array('clinical.consultations.read', $permissions, true)
            || in_array('clinical.consultations.create', $permissions, true);

        $isNurse = (in_array('clinical.triage.record', $permissions, true)
            || in_array('clinical.triage.read', $permissions, true)
            || in_array('clinical.vitals.record', $permissions, true)
            || in_array('encounter.triage.create', $permissions, true)
            || in_array('encounter.triage.read', $permissions, true))
            && ! $isClinician;

        $isBilling = in_array('billing.access', $permissions, true)
            || in_array('billing.invoices.read', $permissions, true);

        // ── 1. Column 1: Live Real-Time Inbox Categories ──
        $arrivedCount = 0;
        $activeCount = 0;
        $dischargedCount = 0;
        $totalTodayEncountersCount = 0;
        $scheduledTodayCount = 0;
        $referralsCount = 0;

        if ($facilityId !== null && Str::isUuid($facilityId)) {
            if (in_array('encounter.encounters.read', $permissions, true)) {
                try {
                    /** @var list<EncounterSummary> $todayEncounters */
                    $todayEncounters = $this->queryBus->dispatch(new ListFacilityEncounters(
                        facilityId: $facilityId,
                        date: $today,
                        statuses: null,
                        limit: 200,
                    ));
                    $totalTodayEncountersCount = count($todayEncounters);

                    foreach ($todayEncounters as $e) {
                        if ($e->status === 'ARRIVED') {
                            $arrivedCount++;
                        } elseif ($e->status === 'ACTIVE') {
                            $activeCount++;
                        } elseif ($e->status === 'DISCHARGED') {
                            $dischargedCount++;
                        }
                    }

                    // Also check for active attendances from previous dates
                    /** @var list<EncounterSummary> $activePastEncounters */
                    $activePastEncounters = $this->queryBus->dispatch(new ListFacilityEncounters(
                        facilityId: $facilityId,
                        date: null,
                        statuses: ['ARRIVED', 'ACTIVE'],
                        limit: 100,
                    ));
                    foreach ($activePastEncounters as $ae) {
                        if ($ae->arrivedAt !== null && ! str_starts_with($ae->arrivedAt, $today->toDateString())) {
                            if ($ae->status === 'ARRIVED') {
                                $arrivedCount++;
                            } elseif ($ae->status === 'ACTIVE') {
                                $activeCount++;
                            }
                        }
                    }
                } catch (\Throwable) {
                    $arrivedCount = 0;
                    $activeCount = 0;
                    $dischargedCount = 0;
                    $totalTodayEncountersCount = 0;
                }
            }

            try {
                $todayAppointments = $this->schedulingApi->getDailyFacilityAppointments($facilityId, $today);
                $scheduledTodayCount = count($todayAppointments);
            } catch (\Throwable) {
                $scheduledTodayCount = 0;
            }

            try {
                $inboundReferrals = $this->careCoordinationApi->searchReferrals($facilityId);
                $referralsCount = count($inboundReferrals);
            } catch (\Throwable) {
                $referralsCount = 0;
            }
        }

        $inboxCategories = $this->buildInboxCategories(
            isReceptionist: $isReceptionist,
            isClinician: $isClinician,
            isNurse: $isNurse,
            isBilling: $isBilling,
            arrivedCount: $arrivedCount,
            activeCount: $activeCount,
            dischargedCount: $dischargedCount,
            totalTodayEncountersCount: $totalTodayEncountersCount,
            scheduledTodayCount: $scheduledTodayCount,
            referralsCount: $referralsCount,
        );

        $calloutNotice = match (true) {
            $isReceptionist => [
                'text' => 'Fast Patient Intake: Register walk-in patients or search the Master Patient Index.',
                'actionLabel' => 'Open Reception Desk',
                'href' => '/workspaces/reception',
            ],
            $isClinician => [
                'text' => 'Clinical Documentation: Ensure all completed patient consultations have recorded notes and diagnoses.',
                'actionLabel' => 'Open Clinical Workspace',
                'href' => '/workspaces/clinical',
            ],
            $isNurse => [
                'text' => 'Nursing Triage: Prioritize arrival triage assessments and record baseline vital signs.',
                'actionLabel' => 'Open Triage Roster',
                'href' => '/workspaces/reception',
            ],
            $isBilling => [
                'text' => 'Cashier Clearance: Verify invoice settlement and insurance pre-authorization claims.',
                'actionLabel' => 'Open Billing Workspace',
                'href' => '/workspaces/billing',
            ],
            default => [
                'text' => 'Facility Operations: Review patient flow, queue routing, and daily attendance records.',
                'actionLabel' => 'View Operations Desk',
                'href' => '/workspaces/reception',
            ],
        };

        // ── 2. Column 2: 7-Day Navigation Strip, Live Encounters & Scheduled Appointments ──
        $weekStart = $selectedDate->startOfWeek(CarbonImmutable::SUNDAY);
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

        $appointments = [];
        if ($facilityId !== null && Str::isUuid($facilityId)) {
            // 2a. Fetch live check-in encounters for this facility & date
            $facilityEncounters = [];
            if (in_array('encounter.encounters.read', $permissions, true)) {
                try {
                    /** @var list<EncounterSummary> $facilityEncounters */
                    $facilityEncounters = $this->queryBus->dispatch(new ListFacilityEncounters(
                        facilityId: $facilityId,
                        date: $selectedDate,
                        statuses: null,
                        limit: 100,
                    ));
                } catch (\Throwable) {
                    $facilityEncounters = [];
                }
            }

            // 2b. Fetch scheduled calendar appointments for this facility & date
            $rawAppointments = [];
            try {
                /** @var list<AppointmentSummary> $rawAppointments */
                $rawAppointments = $this->schedulingApi->getDailyFacilityAppointments($facilityId, $selectedDate);
            } catch (\Throwable) {
                $rawAppointments = [];
            }

            // 2c. Batch fetch patient summaries for real demographics
            $patientIds = [];
            foreach ($facilityEncounters as $e) {
                $patientIds[$e->patientId] = true;
            }
            foreach ($rawAppointments as $a) {
                $patientIds[$a->patientId] = true;
            }

            /** @var array<string, PatientSummary> $patients */
            $patients = [];
            if ($patientIds !== []) {
                try {
                    $patients = $this->patientApi->getPatientSummaries(array_keys($patientIds));
                } catch (\Throwable) {
                    $patients = [];
                }
            }

            // 2d. Map Check-In Encounters (real patients checked in at Reception)
            foreach ($facilityEncounters as $enc) {
                $patient = $patients[$enc->patientId] ?? null;
                $patientName = $patient !== null ? $patient->displayName : 'Patient '.substr($enc->patientId, 0, 8);
                $chartNumber = $patient !== null ? '#'.$patient->patientNumber : '#'.$enc->encounterNumber;
                $ageGender = $patient !== null ? $this->formatAgeSex($patient->dateOfBirth, $patient->sex) : null;

                $timeStr = $enc->arrivedAt ?? $enc->startedAt;
                $timeFormatted = $timeStr !== null
                    ? CarbonImmutable::parse($timeStr)->format('h:i A')
                    : CarbonImmutable::now()->format('h:i A');

                $reason = Str::headline(strtolower($enc->encounterType)).' Attendance';

                $status = match ($enc->status) {
                    'ARRIVED' => 'CHECKED_IN',
                    'ACTIVE' => $enc->unitId !== null ? 'PREPPED' : 'EXAM',
                    'DISCHARGED' => 'COMPLETED',
                    'CANCELLED' => 'CANCELLED',
                    default => $enc->status,
                };

                $room = $enc->unitId !== null ? Str::headline(strtolower($enc->unitId)) : null;

                $appointments[] = [
                    'id' => $enc->encounterId,
                    'time' => $timeFormatted,
                    'patientName' => $patientName,
                    'patientId' => $enc->patientId,
                    'chartNumber' => $chartNumber,
                    'ageGender' => $ageGender,
                    'reason' => $reason,
                    // No provider attribution: neither EncounterSummary nor AppointmentSummary
                    // carries provider identity via published contracts (Encounter §98;
                    // Scheduling resourceId is a schedulable-resource ref, not a staffId).
                    // Never attribute rows to the viewing user.
                    'providerName' => null,
                    'status' => $status,
                    'room' => $room,
                    'type' => 'ENCOUNTER',
                    'href' => '/workspaces/reception',
                ];
            }

            // 2e. Map Scheduled Appointments (avoid duplicate if patient already checked in)
            $encounteredPatientIds = array_flip(array_column($appointments, 'patientId'));

            foreach ($rawAppointments as $apt) {
                if (isset($encounteredPatientIds[$apt->patientId])) {
                    continue;
                }

                $patient = $patients[$apt->patientId] ?? null;
                $patientName = $patient !== null ? $patient->displayName : 'Patient '.substr($apt->patientId, 0, 8);
                $chartNumber = $patient !== null ? '#'.$patient->patientNumber : null;
                $ageGender = $patient !== null ? $this->formatAgeSex($patient->dateOfBirth, $patient->sex) : null;

                $timeFormatted = CarbonImmutable::parse($apt->scheduledStart)->format('h:i A');

                $appointments[] = [
                    'id' => $apt->id,
                    'time' => $timeFormatted,
                    'patientName' => $patientName,
                    'patientId' => $apt->patientId,
                    'chartNumber' => $chartNumber,
                    'ageGender' => $ageGender,
                    'reason' => $apt->reason ?? 'Scheduled Consultation',
                    // No provider attribution: neither EncounterSummary nor AppointmentSummary
                    // carries provider identity via published contracts (Encounter §98;
                    // Scheduling resourceId is a schedulable-resource ref, not a staffId).
                    // Never attribute rows to the viewing user.
                    'providerName' => null,
                    'status' => $apt->status,
                    'room' => null,
                    'type' => 'APPOINTMENT',
                    'href' => '/workspaces/reception',
                ];
            }

            // 2f. Chronological sort by arrival / scheduled time
            usort($appointments, static function (array $a, array $b): int {
                return strcmp($a['time'], $b['time']);
            });
        }

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $dayDate = $weekStart->addDays($i);
            $isDayToday = $dayDate->isSameDay($today);
            $isDaySelected = $dayDate->isSameDay($selectedDate);

            $days[] = [
                'dayOfWeek' => strtoupper($dayDate->format('D')),
                'date' => $dayDate->toDateString(),
                'label' => strtoupper($dayDate->format('D n/j')),
                'isToday' => $isDayToday,
                'isSelected' => $isDaySelected,
            ];
        }

        $statusOptions = [
            ['id' => 'ALL', 'label' => 'All Patients', 'shortLabel' => 'All Patients'],
            ['id' => 'ARRIVED', 'label' => 'Waiting (Arrived)', 'shortLabel' => 'Waiting'],
            ['id' => 'ACTIVE', 'label' => 'In Service (Exam / Obs)', 'shortLabel' => 'In Service'],
            ['id' => 'COMPLETED', 'label' => 'Completed (Discharged)', 'shortLabel' => 'Completed'],
            ['id' => 'SCHEDULED', 'label' => 'Scheduled Appointments', 'shortLabel' => 'Scheduled'],
        ];

        $defaultStatus = $isNurse ? 'ARRIVED' : 'ALL';
        $selectedStatus = ($statusInput !== null && $statusInput !== '')
            ? strtoupper($statusInput)
            : $defaultStatus;

        if (! in_array($selectedStatus, ['ALL', 'ARRIVED', 'ACTIVE', 'COMPLETED', 'SCHEDULED'], true)) {
            $selectedStatus = $defaultStatus;
        }

        $providers = [
            ['id' => 'all', 'name' => 'All Clinic Providers'],
            ['id' => (string) $user->id, 'name' => (string) $user->name],
        ];

        // ── 3. Column 3: Network Alert & Bulletins ──
        $alert = [
            'id' => 'alert_01',
            'title' => 'Service Alert',
            'headline' => 'System Notice: Daily node data synchronization active',
            'body' => 'Real-time synchronization between reception intake, diagnostic queues, and central records is running normally.',
            'severity' => 'info',
        ];

        $messageGroups = $this->buildMessageGroups($today, $isClinician, $isNurse, $isBilling);

        // ── 4. Operational snapshot via Reporting projections (doc 29, ADR-095) ──
        // Cross-domain figures come from the Reporting read models, never computed
        // in the page. Slices are role-tailored: every tile respects auth scope.
        $kpis = null;
        $asOf = null;
        if ($facilityId !== null && Str::isUuid($facilityId)) {
            try {
                $dto = $this->reportingApi->getDashboardKpis($facilityId, $selectedDate->toDateString());
                $asOf = $dto->asOfDate;
                $kpis = [
                    'encounters' => $dto->encounters,
                    'lab' => $dto->lab,
                ];
                $seesOpsWide = $isBilling || (! $isReceptionist && ! $isClinician && ! $isNurse);
                if ($seesOpsWide) {
                    $kpis['financials'] = $dto->financials;
                    $kpis['bedOccupancy'] = $dto->bedOccupancy;
                    $kpis['pharmacy'] = $dto->pharmacy;
                }
            } catch (\Throwable) {
                $kpis = null;
                $asOf = null;
            }
        }

        return [
            'inboxCategories' => $inboxCategories,
            'calloutNotice' => $calloutNotice,
            'days' => $days,
            'weekLabel' => $weekLabel,
            'selectedDate' => $selectedDate->toDateString(),
            'statusOptions' => $statusOptions,
            'selectedStatus' => $selectedStatus,
            'providers' => $providers,
            'selectedProviderId' => $providerId ?? 'all',
            'appointments' => $appointments,
            'alert' => $alert,
            'messageGroups' => $messageGroups,
            'kpis' => $kpis,
            'asOf' => $asOf,
        ];
    }

    /**
     * Format age and sex into standard clinical display (e.g., '45y, Male').
     */
    private function formatAgeSex(?string $dateOfBirth, ?string $sex): ?string
    {
        $parts = [];
        if ($dateOfBirth !== null && $dateOfBirth !== '') {
            try {
                $years = CarbonImmutable::parse($dateOfBirth)->age;
                $parts[] = "{$years}y";
            } catch (\Throwable) {
                // Ignore parse error
            }
        }
        if ($sex !== null && $sex !== '') {
            $parts[] = ucfirst(strtolower($sex));
        }

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    /**
     * @return list<array{id: string, label: string, count: number, href: string}>
     */
    private function buildInboxCategories(
        bool $isReceptionist,
        bool $isClinician,
        bool $isNurse,
        bool $isBilling,
        int $arrivedCount,
        int $activeCount,
        int $dischargedCount,
        int $totalTodayEncountersCount,
        int $scheduledTodayCount,
        int $referralsCount,
    ): array {
        if ($isReceptionist) {
            return [
                ['id' => 'waiting_intake', 'label' => 'Waiting at Reception', 'count' => $arrivedCount, 'href' => '/workspaces/reception'],
                ['id' => 'scheduled_today', 'label' => 'Scheduled Today', 'count' => $scheduledTodayCount, 'href' => '/workspaces/reception'],
                ['id' => 'inbound_referrals', 'label' => 'Inbound Referrals', 'count' => $referralsCount, 'href' => '/workspaces/reception'],
                ['id' => 'active_visits', 'label' => 'Active Clinic Visits', 'count' => $activeCount, 'href' => '/workspaces/reception'],
                ['id' => 'discharged_today', 'label' => 'Discharged Today', 'count' => $dischargedCount, 'href' => '/workspaces/reception'],
                ['id' => 'total_attendances', 'label' => 'Total Attendances', 'count' => $totalTodayEncountersCount, 'href' => '/workspaces/reception'],
            ];
        }

        if ($isClinician) {
            return [
                ['id' => 'encounters', 'label' => 'Encounters to Document', 'count' => $arrivedCount + $activeCount, 'href' => '/workspaces/clinical'],
                ['id' => 'scheduled_appointments', 'label' => 'Scheduled Consultations', 'count' => $scheduledTodayCount, 'href' => '/workspaces/clinical'],
                ['id' => 'inbound_referrals', 'label' => 'Inbound Referrals', 'count' => $referralsCount, 'href' => '/workspaces/clinical'],
                ['id' => 'active_visits', 'label' => 'Active Consultations', 'count' => $activeCount, 'href' => '/workspaces/clinical'],
                ['id' => 'completed_consultations', 'label' => 'Completed Today', 'count' => $dischargedCount, 'href' => '/workspaces/clinical'],
                ['id' => 'total_attendances', 'label' => 'Total Attendances', 'count' => $totalTodayEncountersCount, 'href' => '/workspaces/clinical'],
            ];
        }

        if ($isNurse) {
            return [
                ['id' => 'triage_queue', 'label' => 'Awaiting Triage (Vitals)', 'count' => $arrivedCount, 'href' => '/workspaces/reception'],
                ['id' => 'active_in_rooms', 'label' => 'Active in Exam / Obs', 'count' => $activeCount, 'href' => '/workspaces/clinical'],
                ['id' => 'scheduled_today', 'label' => 'Scheduled Arrivals', 'count' => $scheduledTodayCount, 'href' => '/workspaces/reception'],
                ['id' => 'discharged_today', 'label' => 'Discharged Today', 'count' => $dischargedCount, 'href' => '/workspaces/reception'],
                ['id' => 'total_attendances', 'label' => 'Total Attendances', 'count' => $totalTodayEncountersCount, 'href' => '/workspaces/reception'],
            ];
        }

        if ($isBilling) {
            return [
                ['id' => 'pending_clearance', 'label' => 'Arrived Awaiting Clearance', 'count' => $arrivedCount, 'href' => '/workspaces/billing'],
                ['id' => 'unbilled_encounters', 'label' => 'Discharged Attendances', 'count' => $dischargedCount, 'href' => '/workspaces/billing'],
                ['id' => 'active_patients', 'label' => 'Active Attendances', 'count' => $activeCount, 'href' => '/workspaces/billing'],
                ['id' => 'scheduled_today', 'label' => 'Scheduled Patients', 'count' => $scheduledTodayCount, 'href' => '/workspaces/billing'],
            ];
        }

        // Default / Administrative
        return [
            ['id' => 'encounters', 'label' => 'Today Attendances', 'count' => $totalTodayEncountersCount, 'href' => '/workspaces/reception'],
            ['id' => 'active_flow', 'label' => 'Active Clinic Flow', 'count' => $arrivedCount + $activeCount, 'href' => '/workspaces/reception'],
            ['id' => 'scheduled_appointments', 'label' => 'Scheduled Appointments', 'count' => $scheduledTodayCount, 'href' => '/workspaces/reception'],
            ['id' => 'inbound_referrals', 'label' => 'Inbound Referrals', 'count' => $referralsCount, 'href' => '/workspaces/reception'],
            ['id' => 'discharged_today', 'label' => 'Discharged Today', 'count' => $dischargedCount, 'href' => '/workspaces/reception'],
        ];
    }

    /**
     * @return list<array{date: string, items: list<array{id: string, title: string, isAlert?: bool, href?: string}>}>
     */
    private function buildMessageGroups(
        CarbonImmutable $today,
        bool $isClinician,
        bool $isNurse,
        bool $isBilling,
    ): array {
        $todayStr = $today->format('D M j Y');
        $yesterdayStr = $today->subDay()->format('D M j Y');
        $prevStr = $today->subDays(2)->format('D M j Y');

        if ($isClinician) {
            return [
                [
                    'date' => $todayStr,
                    'items' => [
                        ['id' => 'm1', 'title' => 'Clinical Practice Advisory: National Standard Treatment Guidelines (STG) updated', 'isAlert' => true, 'href' => '/workspaces/clinical'],
                        ['id' => 'm2', 'title' => 'Laboratory & Imaging Alert: Diagnostic order SLA and critical result notification protocols', 'href' => '/workspaces/clinical'],
                        ['id' => 'm3', 'title' => 'MOH Circular: Fever & acute infectious screening guidance compliance required', 'href' => '/workspaces/clinical'],
                    ],
                ],
                [
                    'date' => $yesterdayStr,
                    'items' => [
                        ['id' => 'm4', 'title' => 'Prescriber Security: Two-factor credentialing required for controlled substance orders', 'href' => '/workspaces/clinical'],
                    ],
                ],
                [
                    'date' => $prevStr,
                    'items' => [
                        ['id' => 'm5', 'title' => 'Clinical Audit: Antimicrobial stewardship and prescription review guidelines', 'href' => '/workspaces/clinical'],
                    ],
                ],
            ];
        }

        if ($isNurse) {
            return [
                [
                    'date' => $todayStr,
                    'items' => [
                        ['id' => 'm1', 'title' => 'Nursing Alert: Rapid triage scoring and vital signs capture required for all arrivals', 'isAlert' => true, 'href' => '/workspaces/reception'],
                        ['id' => 'm2', 'title' => 'Bed & Ward Sync: Digital bed assignment and patient transfer protocol active', 'href' => '/workspaces/clinical'],
                    ],
                ],
                [
                    'date' => $yesterdayStr,
                    'items' => [
                        ['id' => 'm3', 'title' => 'Shift Handover: Review floor attendance roster and pending observation charts', 'href' => '/workspaces/reception'],
                    ],
                ],
            ];
        }

        if ($isBilling) {
            return [
                [
                    'date' => $todayStr,
                    'items' => [
                        ['id' => 'm1', 'title' => 'Payer Advisory: National Health Insurance (SHA / NHIF) biometric validation required', 'isAlert' => true, 'href' => '/workspaces/billing'],
                        ['id' => 'm2', 'title' => 'POS Terminal & Cash Drawer: Shift settlement and reconciliation procedure active', 'href' => '/workspaces/billing'],
                    ],
                ],
                [
                    'date' => $yesterdayStr,
                    'items' => [
                        ['id' => 'm3', 'title' => 'Tariff Update: Standard facility fee schedule and payer-contracted tariff synced', 'href' => '/workspaces/billing'],
                    ],
                ],
            ];
        }

        // Default / Admin
        return [
            [
                'date' => $todayStr,
                'items' => [
                    ['id' => 'm1', 'title' => 'Statutory Return Notice: Monthly HMIS summary indicator consolidation reminder', 'isAlert' => true, 'href' => '/workspaces/reception'],
                    ['id' => 'm2', 'title' => 'Data Synchronization: Offline-resilient node and central records replication active', 'href' => '/workspaces/reception'],
                ],
            ],
            [
                'date' => $yesterdayStr,
                'items' => [
                    ['id' => 'm3', 'title' => 'Security Audit: Access control, permission scopes, and session ledger updated', 'href' => '/workspaces/reception'],
                ],
            ],
        ];
    }
}
