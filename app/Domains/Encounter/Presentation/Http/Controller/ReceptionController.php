<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Presentation\Http\Controller;

use App\Domains\Clinical\Contracts\ClinicalApi;
use App\Domains\Clinical\Contracts\Dto\ProcedureDefinitionSummary;
use App\Domains\Clinical\Contracts\Dto\ProcedureProtocolApprovalSummary;
use App\Domains\Encounter\Domain\EncounterReader;
use App\Domains\Encounter\Presentation\Http\DTO\ReceptionEncounterItem;
use App\Domains\Encounter\Presentation\Http\Presenter\ReceptionPresenter;
use App\Domains\Patient\Contracts\Dto\PatientSearchResult;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Patient\Domain\Exception\SearchCriteriaTooBroad;
use App\Models\User;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class ReceptionController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        ReceptionPresenter $presenter,
        PatientApi $patientApi,
        EncounterReader $encounterReader,
        ClinicalApi $clinicalApi,
        QueueingApi $queueingApi,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        // Merged front-desk workspace: entry needs ANY of the section
        // permissions (chart-section pattern). Each denied section composes
        // empty with a `restricted` flag instead of leaking rows.
        $canReadEncounters = $user->can('encounter.encounters.read');
        $canReadAppointments = $user->can('sch.access');
        $canReadPatients = $user->can('patient.patients.read');
        abort_unless($canReadEncounters || $canReadAppointments || $canReadPatients, 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $dateInput = $request->query('date');
        // Strict calendar-date only (createFromFormat alone would silently
        // roll "2026-13-45" over into 2027). Anything else — including a
        // hand-edited ?date= — falls back to today instead of crashing.
        // Days are facility-wall-clock days (Africa/Dar_es_Salaam), never
        // UTC days: a 00:30 EAT arrival belongs to the local date.
        $facilityTimeZone = (string) config('app.facility_timezone', 'Africa/Dar_es_Salaam');
        $date = CarbonImmutable::now($facilityTimeZone)->startOfDay();
        if (is_string($dateInput)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateInput, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $date = CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, $facilityTimeZone)
                ?? CarbonImmutable::now($facilityTimeZone)->startOfDay();
        }

        $statusInput = $request->input('status', 'ALL');
        $statusValues = is_string($statusInput)
            ? explode(',', $statusInput)
            : (is_array($statusInput) ? $statusInput : []);
        $requestedStatuses = [];
        foreach ($statusValues as $statusValue) {
            if (! is_string($statusValue)) {
                continue;
            }

            $statusValue = strtoupper(trim($statusValue));
            if (in_array($statusValue, ['BOOKED', 'ARRIVED', 'ACTIVE', 'COMPLETED'], true)) {
                $requestedStatuses[$statusValue] = true;
            }
        }
        $encounterStatuses = array_values(array_filter(
            ['BOOKED', 'ARRIVED', 'ACTIVE', 'COMPLETED'],
            static fn (string $status): bool => isset($requestedStatuses[$status]),
        ));
        if ($encounterStatuses === []) {
            $encounterStatuses = null;
        }
        $rosterFilter = mb_substr(trim((string) $request->input('filter', '')), 0, 80);

        $props = $presenter->present(
            facilityId: $facilityId,
            date: $date,
            facilityTimeZone: $facilityTimeZone,
            encounterStatuses: $encounterStatuses,
            rosterFilter: $rosterFilter,
            includeEncounters: $canReadEncounters,
            includeAppointments: $canReadAppointments,
            includePatientDetails: $canReadPatients,
            includeTickets: $user->can('queue.ticket.view'),
        );

        $protocolProcedures = [];
        if ($user->can('clinical.procedures.protocol_request')
            && $user->can('clinical.procedures.read')
            && $user->can('queue.ticket.place')) {
            /** @var list<ProcedureDefinitionSummary> $definitions */
            $definitions = $clinicalApi->getProcedureDefinitions();
            /** @var list<ProcedureProtocolApprovalSummary> $approvals */
            $approvals = $clinicalApi->getDirectProcedureProtocolApprovals($facilityId);
            $approvalByDefinition = [];
            foreach ($approvals as $approval) {
                $approvalByDefinition[$approval->definitionId] = $approval;
            }
            foreach ($definitions as $definition) {
                $approval = $approvalByDefinition[$definition->definitionId] ?? null;
                if (! $approval instanceof ProcedureProtocolApprovalSummary
                    || $approval->definitionVersion !== $definition->version) {
                    continue;
                }
                $queueId = $queueingApi->procedureQueueId($facilityId, $definition->definitionId);
                if ($queueId === null) {
                    continue;
                }
                $queue = collect($props->availableQueues)->first(
                    static fn (array $candidate): bool => ($candidate['id'] ?? null) === $queueId,
                );
                if ($queue === null) {
                    continue;
                }
                $protocolProcedures[] = [
                    'definitionId' => $definition->definitionId,
                    'code' => $definition->code,
                    'name' => $definition->name,
                    'queueId' => $queueId,
                    'queueName' => (string) $queue['name'],
                    'protocolReference' => $approval->protocolReference,
                    'protocolVersion' => $approval->protocolVersion,
                ];
            }
        }

        $restricted = $props->restricted;
        if (! $canReadPatients) {
            $restricted[] = 'patients';
        }

        $preselectedPatient = null;
        $activeEncounter = null;
        $searchResults = null;
        $searchError = null;

        if ($canReadPatients && $request->filled('patient_id')) {
            $targetPatientId = trim((string) $request->input('patient_id'));
            if (Str::isUuid($targetPatientId)) {
                try {
                    $summary = $patientApi->getPatientSummary($targetPatientId);
                    $preselectedPatient = [
                        'patientId' => $summary->patientId,
                        'patientNumber' => $summary->patientNumber,
                        'displayName' => $summary->displayName,
                        'sex' => $summary->sex,
                        'status' => $summary->status,
                        'isProvisional' => $summary->isProvisional,
                        'hasAccessibleFacilityRegistration' => true,
                        'survivingPatientId' => null,
                    ];

                    $activeEnc = $canReadEncounters
                        ? $encounterReader->activeForPatient($facilityId, $targetPatientId)
                        : null;
                    if ($activeEnc !== null) {
                        $activeEncounter = ReceptionEncounterItem::fromSummary($activeEnc, $summary);
                    }
                } catch (\Throwable) {
                    $preselectedPatient = null;
                    $activeEncounter = null;
                }
            }
        }

        $q = null;
        if ($canReadPatients && $preselectedPatient === null) {
            $q = $request->filled('q') ? trim((string) $request->input('q')) : ($request->filled('search_patient') ? trim((string) $request->input('search_patient')) : null);
        }

        if ($q !== null && $q !== '') {
            $cleanDigits = (string) preg_replace('/\D+/', '', $q);
            $isPatPrefix = str_starts_with(strtoupper($q), 'PAT-') ||
                str_starts_with(strtoupper($q), 'PAT ') ||
                preg_match('/^PAT\d+$/i', $q);

            $patientNumber = null;
            $nationalId = null;
            $insuranceId = null;
            $phone = null;
            $nameFragment = null;

            if ($isPatPrefix) {
                $patientNumber = $q;
            } elseif (preg_match('/^#\d+$/', $q)) {
                $patientNumber = ltrim($q, '#');
            } elseif ($cleanDigits !== '' && strlen($cleanDigits) >= 9 && strlen($cleanDigits) <= 15 && (str_starts_with($q, '+') || str_starts_with($q, '0') || strlen($cleanDigits) >= 10)) {
                $phone = $cleanDigits;
                $nationalId = $q;
            } else {
                if (ctype_digit($q) && strlen($q) <= 8) {
                    $patientNumber = $q;
                }
                if (preg_match('/[0-9]/', $q)) {
                    $nationalId = $q;
                    $insuranceId = $q;
                }
                if (mb_strlen(str_replace(' ', '', $q)) >= 3) {
                    $nameFragment = $q;
                }
            }

            try {
                /** @var LengthAwarePaginator<int, PatientSearchResult> $paginator */
                $paginator = $patientApi->searchPatients(
                    patientNumber: $patientNumber,
                    nationalId: $nationalId,
                    insuranceId: $insuranceId,
                    phone: $phone,
                    nameFragment: $nameFragment,
                    page: 1,
                    perPage: 10,
                );

                $searchResults = array_map(static fn (PatientSearchResult $r): array => [
                    'patientId' => $r->patientId,
                    'patientNumber' => $r->patientNumber,
                    'displayName' => $r->displayName,
                    'sex' => $r->sex,
                    'status' => $r->status,
                    'isProvisional' => $r->isProvisional,
                    'hasAccessibleFacilityRegistration' => $r->hasAccessibleFacilityRegistration,
                    'survivingPatientId' => $r->survivingPatientId,
                ], $paginator->items());
            } catch (SearchCriteriaTooBroad $e) {
                $searchResults = [];
                $searchError = $e->getMessage();
            } catch (\Throwable) {
                $searchResults = [];
            }
        }

        return Inertia::render('Reception/Index', [
            'facilityId' => $props->facilityId,
            'date' => $props->date,
            'timezone' => $props->timezone,
            'encounters' => $props->encounters,
            'appointments' => $props->appointments,
            'metrics' => $props->metrics,
            'availableQueues' => $props->availableQueues,
            'protocolProcedures' => $protocolProcedures,
            'searchResults' => $searchResults,
            'searchError' => $searchError,
            'preselectedPatient' => $preselectedPatient,
            'activeEncounter' => $activeEncounter,
            'referrals' => $props->referrals,
            'rosterStatus' => $props->rosterStatus,
            'rosterFilter' => $props->rosterFilter,
            'weekLabel' => $props->weekLabel,
            'days' => $props->days,
            'restricted' => $restricted,
        ]);
    }
}
