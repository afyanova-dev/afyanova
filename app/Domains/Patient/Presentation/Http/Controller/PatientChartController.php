<?php

declare(strict_types=1);

namespace App\Domains\Patient\Presentation\Http\Controller;

use App\Domains\Billing\Contracts\BillingApi;
use App\Domains\CareCoordination\Application\Query\GetPatientCareEpisodes;
use App\Domains\CareCoordination\Application\Query\GetPatientReferrals;
use App\Domains\Clinical\Application\Query\GetPatientAllergies;
use App\Domains\Clinical\Application\Query\GetPatientClinicalPlans;
use App\Domains\Clinical\Application\Query\GetPatientProblems;
use App\Domains\Clinical\Application\Query\GetPatientProcedures;
use App\Domains\Clinical\Application\Query\GetPatientVitalSigns;
use App\Domains\Encounter\Application\Query\GetPatientEncounters;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Laboratory\Application\Query\GetOrderResults;
use App\Domains\Laboratory\Application\Query\GetPatientLabOrders;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Domains\Patient\Application\Query\GetPatientProfile;
use App\Domains\Patient\Contracts\Dto\PatientProfileData;
use App\Domains\Pharmacy\Application\Query\GetPatientPrescriptions;
use App\Domains\Radiology\Application\Query\GetPatientImagingOrders;
use App\Domains\Scheduling\Application\Query\GetAppointmentTypes;
use App\Domains\Scheduling\Application\Query\GetPatientAppointments;
use App\Domains\Scheduling\Application\Query\GetSchedulableResources;
use App\Models\User;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * OpenMRS-style patient chart composition (Phase 5+).
 *
 * THE single patient chart controller. Both the canonical
 * `/patient/{id}/chart/{tab?}` route and the legacy
 * `/workspaces/patient/{id}` alias point here, so the two URLs cannot
 * diverge. Composes one section per chart dashboard, each read through its
 * owning domain's protected query or port. A section the caller may not see,
 * or whose read fails, lands in `unavailable` — the chart renders an explicit
 * state instead of an empty widget or a 500. No domain rules are duplicated
 * here; every list is mapped to display fields only.
 */
final class PatientChartController
{
    /**
     * @var list<string>
     */
    private array $unavailable = [];

    /**
     * Sections the caller may not see at all (permission denied).
     *
     * @var list<string>
     */
    private array $restricted = [];

    public function __invoke(
        string $patientId,
        Request $request,
        TenantContext $context,
        QueryBus $queryBus,
        EncounterApi $encounterApi,
        QueueingApi $queueingApi,
        BillingApi $billingApi,
        OrganizationApi $organizationApi,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        abort_unless($user->can('patient.patients.read'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        // Per-request state: the controller instance may be shared across
        // requests (long-lived processes), so never accumulate across calls.
        $this->unavailable = [];
        $this->restricted = [];

        try {
            /** @var PatientProfileData $profile */
            $profile = $queryBus->dispatch(new GetPatientProfile($patientId, $facilityId));
        } catch (NotFound) {
            abort(404, 'Patient not found.');
        }

        $activeEncounter = null;
        if ($user->can('encounter.encounters.read')) {
            try {
                $summary = $encounterApi->findActiveEncounterForPatient($facilityId, $patientId);
                if ($summary !== null) {
                    $activeEncounter = [
                        'encounterId' => $summary->encounterId,
                        'encounterNumber' => $summary->encounterNumber,
                        'encounterType' => $summary->encounterType,
                        'status' => $summary->status,
                        'departmentId' => $summary->departmentId,
                        'unitId' => $summary->unitId,
                        'arrivedAt' => $summary->arrivedAt,
                        'startedAt' => $summary->startedAt,
                    ];
                }
            } catch (\Throwable) {
                $activeEncounter = null;
            }
        }

        $availableQueues = [];
        if ($user->can('encounter.encounters.create')) {
            try {
                $queues = $queueingApi->listFacilityQueues($facilityId, true);
                $availableQueues = array_map(static fn ($q): array => [
                    'id' => $q->id,
                    'departmentId' => $q->departmentId,
                    'name' => $q->name,
                    'code' => $q->code,
                    'serviceCapability' => $q->serviceCapability,
                ], $queues);
            } catch (\Throwable) {
                $availableQueues = [];
            }
        }

        $queueEntry = null;
        if ($activeEncounter !== null) {
            try {
                $entry = $queueingApi->getActiveForEncounter((string) $activeEncounter['encounterId']);
                if ($entry !== null) {
                    $queueEntry = [
                        'entryId' => $entry->id,
                        'queueId' => $entry->queueId,
                        'ticketNumber' => $entry->ticketNumber,
                        'priority' => $entry->priority,
                        'status' => $entry->status,
                    ];
                }
            } catch (\Throwable) {
                $queueEntry = null;
            }
        }

        $allergies = $this->section($user, $queryBus, 'allergies', 'clinical.allergies.read',
            static fn (): array => array_values(array_map(self::mapAllergy(...),
                $queryBus->dispatch(new GetPatientAllergies($patientId)))));

        $conditions = $this->section($user, $queryBus, 'conditions', 'clinical.problems.read',
            static fn (): array => array_values(array_map(self::mapProblem(...),
                $queryBus->dispatch(new GetPatientProblems($patientId)))));

        $procedures = $this->section($user, $queryBus, 'procedures', 'clinical.procedures.read',
            static fn (): array => array_values(array_map(self::mapProcedure(...),
                $queryBus->dispatch(new GetPatientProcedures($patientId)))));

        $plans = $this->section($user, $queryBus, 'plans', 'clinical.plans.read',
            static fn (): array => array_values(array_map(self::mapPlan(...),
                $queryBus->dispatch(new GetPatientClinicalPlans($patientId)))));

        $vitals = $this->section($user, $queryBus, 'vitals', 'clinical.vitals.read',
            static fn (): array => array_values(array_map(self::mapVitals(...),
                $queryBus->dispatch(new GetPatientVitalSigns($patientId)))));

        $medications = $this->section($user, $queryBus, 'medications', 'pharmacy.access',
            static fn (): array => array_values(array_map(self::mapPrescription(...),
                $queryBus->dispatch(new GetPatientPrescriptions($patientId)))));

        $labOrders = $this->section($user, $queryBus, 'results', 'laboratory.access',
            static fn (): array => array_values(array_map(self::mapLabOrder(...),
                $queryBus->dispatch(new GetPatientLabOrders($patientId)))));

        $labResults = [];
        if ($labOrders !== null && $user->can('laboratory.access')) {
            $recent = array_slice($labOrders, 0, 5);
            foreach ($recent as $order) {
                try {
                    $rows = $queryBus->dispatch(new GetOrderResults($order['orderId']));
                    $labResults[$order['orderId']] = array_map(self::mapLabResult(...), $rows);
                } catch (\Throwable) {
                    $labResults[$order['orderId']] = [];
                }
            }
        }

        $imagingOrders = $this->section($user, $queryBus, 'orders', 'radiology.access',
            static fn (): array => array_values(array_map(self::mapImagingOrder(...),
                $queryBus->dispatch(new GetPatientImagingOrders($patientId)))));

        // Type/resource display names resolve via the same two batched list
        // queries the daily board uses — fixed cost regardless of row count,
        // never per-appointment lookups.
        $appointments = $this->section($user, $queryBus, 'appointments', 'sch.access',
            static function () use ($queryBus, $patientId, $facilityId): array {
                /** @var array<string, string> $typeNames */
                $typeNames = [];
                foreach ($queryBus->dispatch(new GetAppointmentTypes($facilityId)) as $type) {
                    $typeNames[$type->id] = $type->name;
                }
                /** @var array<string, string> $resourceNames */
                $resourceNames = [];
                foreach ($queryBus->dispatch(new GetSchedulableResources($facilityId)) as $resource) {
                    $resourceNames[$resource->id] = $resource->name;
                }

                return array_values(array_map(
                    static fn ($a): array => self::mapAppointment($a, $typeNames, $resourceNames),
                    $queryBus->dispatch(new GetPatientAppointments($patientId, $facilityId)),
                ));
            });

        $programs = $this->section($user, $queryBus, 'programs', 'care_coordination.access',
            static fn (): array => array_values(array_map(self::mapCareEpisode(...),
                $queryBus->dispatch(new GetPatientCareEpisodes($patientId)))));

        // Department/unit display names resolve via one facility-structure
        // read — fixed cost regardless of row count, never per-visit
        // lookups. Names are best-effort: a missing structure still yields
        // rows with null names rather than an unavailable section.
        $visits = $this->section($user, $queryBus, 'visits', 'encounter.encounters.read',
            static function () use ($queryBus, $organizationApi, $patientId, $facilityId): array {
                $departmentNames = [];
                $unitNames = [];
                try {
                    $structure = $organizationApi->getFacilityStructure($facilityId);
                    foreach ($structure?->departments ?? [] as $department) {
                        $departmentNames[(string) $department['id']] = (string) $department['name'];
                        foreach ($department['units'] ?? [] as $unit) {
                            $unitNames[(string) $unit['id']] = (string) $unit['name'];
                        }
                    }
                } catch (\Throwable) {
                    $departmentNames = [];
                    $unitNames = [];
                }

                return array_values(array_map(
                    static fn ($e): array => self::mapVisit($e, $departmentNames, $unitNames),
                    $queryBus->dispatch(new GetPatientEncounters($patientId, $facilityId)),
                ));
            });

        $referrals = $this->section($user, $queryBus, 'referrals', 'care_coordination.access',
            static fn (): array => array_values(array_map(self::mapReferral(...),
                $queryBus->dispatch(new GetPatientReferrals($patientId)))));

        $billing = $this->section($user, $queryBus, 'billing-history', 'billing.access',
            static fn (): array => self::billingHistory($billingApi, $patientId, $facilityId));

        return Inertia::render('Patients/Profile', [
            'patient' => [
                'patientId' => $profile->patientId,
                'patientNumber' => $profile->patientNumber,
                'displayName' => $profile->displayName,
                'givenName' => $profile->givenName,
                'familyName' => $profile->familyName,
                'middleName' => $profile->middleName,
                'dateOfBirth' => $profile->dateOfBirth,
                'dobPrecision' => $profile->dobPrecision,
                'sex' => $profile->sex,
                'status' => $profile->status,
                'isProvisional' => $profile->isProvisional,
                'primaryPhone' => $profile->primaryPhone,
                'email' => $profile->email,
                'registrationSource' => $profile->registrationSource,
                'registeredAt' => $profile->registeredAt,
                'hasAccessibleFacilityRegistration' => $profile->hasAccessibleFacilityRegistration,
                'facilityRegistrationCount' => $profile->facilityRegistrationCount,
                'survivingPatientId' => $profile->survivingPatientId,
                'identifiers' => array_map(static fn ($id) => [
                    'id' => $id->id,
                    'type' => $id->type,
                    'value' => $id->value,
                    'isVerified' => $id->isVerified,
                ], $profile->identifiers),
                'addresses' => $profile->addresses,
                'relationships' => $profile->relationships,
                'auditHistory' => $profile->auditHistory,
            ],
            'activeEncounter' => $activeEncounter,
            'availableQueues' => $availableQueues,
            'queueEntry' => $queueEntry,
            'photoUrl' => $profile->photoDocumentId !== null
                ? route('patient.photo', ['patientId' => $profile->patientId])
                : null,
            'chart' => [
                'allergies' => $allergies ?? [],
                'conditions' => $conditions ?? [],
                'procedures' => $procedures ?? [],
                'plans' => $plans ?? [],
                'vitals' => $vitals ?? [],
                'medications' => $medications ?? [],
                'labOrders' => $labOrders ?? [],
                'labResults' => $labResults,
                'imagingOrders' => $imagingOrders ?? [],
                'appointments' => $appointments ?? [],
                'visits' => $visits ?? [],
                'programs' => $programs ?? [],
                'referrals' => $referrals ?? [],
                'billing' => $billing ?? ['account' => null, 'outstanding' => null, 'ledger' => []],
                'unavailable' => $this->unavailable,
                'restricted' => $this->restricted,
            ],
        ]);
    }

    /**
     * @param  callable(): (array<string, mixed>|list<array<string, mixed>>)  $read
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    private function section(User $user, QueryBus $queryBus, string $key, string $permission, callable $read): ?array
    {
        if (! $user->can($permission)) {
            $this->restricted[] = $key;

            return null;
        }

        try {
            return $read();
        } catch (\Throwable) {
            $this->unavailable[] = $key;

            return null;
        }
    }

    /**
     * @return array{account: ?array<string, string|null>, outstanding: ?array<string, mixed>, ledger: list<array<string, string|null>>}
     */
    private static function billingHistory(BillingApi $billingApi, string $patientId, string $facilityId): array
    {
        $account = $billingApi->activeAccountForPatientFacility($patientId, $facilityId);
        if ($account === null) {
            return ['account' => null, 'outstanding' => null, 'ledger' => []];
        }

        return [
            'account' => [
                'accountNumber' => $account->accountNumber,
                'status' => $account->status,
                'currency' => $account->currency,
            ],
            'outstanding' => (array) $billingApi->accountOutstanding($account->financialAccountId),
            'ledger' => array_map(static fn ($e): array => [
                'entryType' => $e->entryType,
                'direction' => $e->direction,
                'amount' => $e->amount,
                'currency' => $e->currency,
                'postedAt' => $e->postedAt,
            ], $billingApi->accountLedger($account->financialAccountId, 25)),
        ];
    }

    /** @return array<string, mixed> */
    private static function mapAllergy(mixed $a): array
    {
        return [
            'id' => $a->allergyId,
            'substance' => $a->substanceName,
            'severity' => $a->severity,
            'status' => $a->clinicalStatus,
            'verification' => $a->verificationStatus,
            'reaction' => $a->reactionDescription,
            'onset' => $a->onsetDate,
        ];
    }

    /** @return array<string, mixed> */
    private static function mapProblem(mixed $p): array
    {
        return [
            'id' => $p->problemId,
            'description' => $p->description,
            'code' => $p->code,
            'status' => $p->status,
            'onset' => $p->onsetDate,
        ];
    }

    /** @return array<string, mixed> */
    private static function mapProcedure(mixed $p): array
    {
        return [
            'id' => $p->procedureId,
            'number' => $p->procedureNumber,
            'category' => $p->procedureCategory,
            'status' => $p->status,
            'outcome' => $p->outcome,
            'completedAt' => $p->completedAt,
        ];
    }

    /** @return array<string, mixed> */
    private static function mapPlan(mixed $p): array
    {
        return [
            'id' => $p->planId,
            'type' => $p->planType,
            'intent' => $p->clinicalIntent,
            'status' => $p->status,
            'followUp' => $p->followUp,
        ];
    }

    /** @return array<string, mixed> */
    private static function mapVitals(mixed $v): array
    {
        return [
            'id' => $v->vitalSignsObservationId,
            'temperature' => $v->temperature,
            'heartRate' => $v->heartRate,
            'respiratoryRate' => $v->respiratoryRate,
            'systolicBp' => $v->systolicBp,
            'diastolicBp' => $v->diastolicBp,
            'oxygenSaturation' => $v->oxygenSaturation,
            'weight' => $v->weight,
            'height' => $v->height,
            'occurredAt' => $v->occurredAt,
        ];
    }

    /** @return array<string, mixed> */
    private static function mapPrescription(mixed $p): array
    {
        return [
            'id' => $p->prescriptionId,
            'facilityId' => $p->facilityId,
            'number' => $p->prescriptionNumber,
            'medication' => $p->medicationName ?? $p->medicationCode,
            'dose' => $p->dose,
            'frequency' => $p->frequency,
            'status' => $p->status,
            'recordedAt' => $p->recordedAt,
        ];
    }

    /** @return array<string, mixed> */
    private static function mapLabOrder(mixed $o): array
    {
        return [
            'orderId' => $o->orderId,
            'orderNumber' => $o->orderNumber,
            'priority' => $o->priority,
            'status' => $o->status,
            'createdAt' => $o->createdAt,
            'items' => array_map(static fn ($i): array => [
                'testCode' => $i->testCode,
                'testName' => $i->testName,
                'status' => $i->status,
            ], $o->items),
        ];
    }

    /** @return array<string, mixed> */
    private static function mapLabResult(mixed $r): array
    {
        $value = $r->valueNumeric ?? $r->valueCode ?? $r->valueText;

        return [
            'parameter' => $r->parameterName ?? $r->parameterCode,
            'value' => $value,
            'unit' => $r->unit,
            'flag' => $r->abnormalFlag ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private static function mapImagingOrder(mixed $o): array
    {
        return [
            'orderId' => $o->orderId,
            'orderNumber' => $o->orderNumber,
            'priority' => $o->priority,
            'status' => $o->status,
            'createdAt' => $o->createdAt,
            'items' => array_map(static fn ($i): array => [
                'serviceCode' => $i->serviceCode ?? null,
                'serviceName' => $i->serviceName ?? null,
                'status' => $i->status,
            ], $o->items),
        ];
    }

    /** @return array<string, mixed> */
    private static function mapAppointment(mixed $a, array $typeNames = [], array $resourceNames = []): array
    {
        return [
            'id' => $a->id,
            'number' => $a->appointmentNumber,
            'resourceId' => $a->resourceId,
            'appointmentTypeId' => $a->appointmentTypeId,
            'appointmentTypeName' => $typeNames[$a->appointmentTypeId] ?? null,
            'resourceName' => $resourceNames[$a->resourceId] ?? null,
            'start' => $a->scheduledStart,
            'end' => $a->scheduledEnd,
            'status' => $a->status,
            'reason' => $a->reason,
        ];
    }

    /** @return array<string, mixed> */
    private static function mapVisit(mixed $e, array $departmentNames = [], array $unitNames = []): array
    {
        return [
            'id' => $e->encounterId,
            'number' => $e->encounterNumber,
            'encounterType' => $e->encounterType,
            'status' => $e->status,
            'disposition' => $e->disposition,
            'departmentId' => $e->departmentId,
            'departmentName' => $departmentNames[$e->departmentId] ?? null,
            'unitId' => $e->unitId,
            'unitName' => $unitNames[$e->unitId] ?? null,
            'arrivedAt' => $e->arrivedAt,
            'startedAt' => $e->startedAt,
            'admittedAt' => $e->admittedAt,
            'completedAt' => $e->completedAt,
        ];
    }

    /** @return array<string, mixed> */
    private static function mapCareEpisode(mixed $e): array
    {
        return [
            'id' => $e->careEpisodeId,
            'type' => $e->type,
            'subtype' => $e->subtype,
            'status' => $e->status,
            'openedAt' => $e->openedAt,
            'closedAt' => $e->closedAt,
        ];
    }

    /** @return array<string, mixed> */
    private static function mapReferral(mixed $r): array
    {
        return [
            'id' => $r->referralId,
            'direction' => $r->direction,
            'specialty' => $r->specialtyRequested,
            'priority' => $r->priority,
            'status' => $r->status,
        ];
    }
}
