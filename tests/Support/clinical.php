<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CreateClinicalNote;
use App\Domains\Clinical\Application\Command\RecordDiagnosis;
use App\Domains\Clinical\Application\Command\RecordTriageAssessment;
use App\Domains\Clinical\Application\Command\RecordVitalSigns;
use App\Domains\Clinical\Application\Command\RequestProcedure;
use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Domains\Clinical\Domain\Model\Diagnosis;
use App\Domains\Clinical\Domain\Model\ProcedureDefinition;
use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Domains\Clinical\Domain\ProcedureDefinitionRepository;
use App\Domains\Clinical\Domain\ValueObject\DiagnosisCode;
use App\Domains\Clinical\Domain\ValueObject\TriagePriority;
use App\Domains\Clinical\Domain\ValueObject\VitalSigns;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Patient\Application\Command\RegisterPatient;
use App\Domains\Patient\Application\Command\RegisterPatientResult;
use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Str;

/*
 |----------------------------------------------------------------------
 | Clinical (PHASE 6 / C.1) test helper
 |----------------------------------------------------------------------
 | Builds the tenant → org → facility → user fixture Clinical feature
 | tests need, plus real Patient / Encounter setup so cross-domain
 | composition is exercised end-to-end (never mocked — AGENTS §43).
 */

if (! function_exists('clinicalFixture')) {
    /**
     * A tenant + user holding the given permission keys. Defaults to the full
     * C.1 clinician key set plus the cross-domain read keys `CreateClinicalNote`
     * needs (`patient.patients.read` for `getPatientSummary`,
     * `encounter.encounters.read` for `getEncounterContext`).
     *
     * @param  list<string>|null  $permissions
     * @param  list<string>  $capabilities
     * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed, role_id: string}
     */
    function clinicalFixture(?array $permissions = null, array $capabilities = ['OUTPATIENT_CARE']): array
    {
        $permissions ??= [
            'clinical.access',
            'clinical.notes.read',
            'clinical.notes.create',
            'clinical.notes.sign',
            'clinical.notes.amend',
            'clinical.vitals.read',
            'clinical.vitals.record',
            'clinical.diagnoses.read',
            'clinical.diagnoses.record',
            'clinical.triage.read',
            'clinical.triage.record',
            'clinical.procedures.read',
            'clinical.procedures.request',
            'clinical.procedures.perform',
            'clinical.procedures.complete',
            'clinical.procedures.amend',
            'patient.patients.read',
            'encounter.encounters.read',
            // cross-domain setup keys the helpers below need (register a patient,
            // open + drive an encounter) — real clinicians would not hold these.
            'patient.patients.create',
            'encounter.encounters.create',
            'encounter.encounters.update',
            'encounter.encounters.manage_disposition',
            'encounter.encounters.complete',
            'encounter.encounters.transfer',
            'encounter.encounters.cancel',
        ];

        $fixture = makeTenantWithUser($permissions, $capabilities);
        test()->actingAs($fixture['user']);

        return $fixture;
    }

    /**
     * Register an ACTIVE patient in the tenant (real Patient CommandBus path).
     *
     * @param  array<string, mixed>  $overrides
     */
    function clinicalRegisterPatient(string $tenantId, string $facilityId, string $actorId, array $overrides = []): RegisterPatientResult
    {
        $command = new RegisterPatient(
            patientId: $overrides['patientId'] ?? (string) Str::uuid7(),
            actorId: $actorId,
            givenName: $overrides['givenName'] ?? 'Clinical',
            familyName: $overrides['familyName'] ?? ('Proof'.Str::random(6)),
            middleName: null,
            displayName: null,
            sex: $overrides['sex'] ?? Sex::Female,
            dateOfBirth: $overrides['dateOfBirth'] ?? '1990-05-20',
            dobPrecision: DobPrecision::Known,
            primaryPhone: null,
            email: null,
            provisional: $overrides['provisional'] ?? false,
        );

        /** @var RegisterPatientResult */
        return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
    }

    /**
     * Register a patient, open an encounter and (by default) start it so it is
     * ACTIVE — the common C.1 precondition. Returns `[patientId, encounterId]`.
     *
     * @return array{0: string, 1: string}
     */
    function clinicalPatientWithEncounter(
        string $tenantId,
        string $facilityId,
        string $actorId,
        EncounterType $type = EncounterType::Outpatient,
        bool $start = true,
    ): array {
        $patientId = clinicalRegisterPatient($tenantId, $facilityId, $actorId)->patientId;
        $encounterId = (string) Str::uuid7();

        TenantContextScope::run($tenantId, $facilityId, function () use ($encounterId, $patientId, $actorId, $type, $start): void {
            $bus = app(CommandBus::class);
            $bus->dispatch(new CreateEncounter($encounterId, $patientId, $actorId, $type));
            if ($start) {
                $bus->dispatch(new StartEncounter($encounterId, $actorId));
            }
        });

        return [$patientId, $encounterId];
    }

    /**
     * Dispatch `CreateClinicalNote` through the real CommandBus and return the
     * new note id.
     *
     * @param  array<string, mixed>  $overrides
     */
    function clinicalCreateNote(
        string $tenantId,
        ?string $facilityId,
        string $patientId,
        string $encounterId,
        string $actorId,
        array $overrides = [],
    ): string {
        $noteId = $overrides['clinicalNoteId'] ?? (string) Str::uuid7();

        TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new CreateClinicalNote(
            clinicalNoteId: $noteId,
            actorId: $actorId,
            patientId: $patientId,
            encounterId: $encounterId,
            noteType: $overrides['noteType'] ?? 'progress_note',
            title: $overrides['title'] ?? 'Progress note',
            body: $overrides['body'] ?? 'Patient reviewed; stable.',
            authoredContext: $overrides['authoredContext'] ?? ClinicalNote::AUTHORED_CONTEXT_CLINICIAN,
            occurredAt: $overrides['occurredAt'] ?? null,
            reason: $overrides['reason'] ?? null,
        )));

        return $noteId;
    }

    /**
     * A plausible full nine-measurement vitals set (C.2 — ADR-138). Overrides
     * merge over the defaults; pass a value of `null` to omit a measurement.
     *
     * @param  array<string, float|int|null>  $overrides
     */
    function sampleVitals(array $overrides = []): VitalSigns
    {
        $v = array_merge([
            'temperature' => 36.9,
            'heartRate' => 76,
            'respiratoryRate' => 15,
            'systolicBp' => 124,
            'diastolicBp' => 80,
            'oxygenSaturation' => 98,
            'weight' => 71.4,
            'height' => 172.0,
            'painScore' => 1,
        ], $overrides);

        return VitalSigns::of(
            temperature: $v['temperature'],
            heartRate: $v['heartRate'],
            respiratoryRate: $v['respiratoryRate'],
            systolicBp: $v['systolicBp'],
            diastolicBp: $v['diastolicBp'],
            oxygenSaturation: $v['oxygenSaturation'],
            weight: $v['weight'],
            height: $v['height'],
            painScore: $v['painScore'],
        );
    }

    /**
     * Dispatch `RecordVitalSigns` through the real CommandBus and return the new
     * observation id.
     *
     * @param  array<string, mixed>  $overrides
     */
    function clinicalRecordVitals(
        string $tenantId,
        ?string $facilityId,
        string $patientId,
        string $encounterId,
        string $actorId,
        array $overrides = [],
    ): string {
        $observationId = $overrides['vitalSignsObservationId'] ?? (string) Str::uuid7();

        TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
            new RecordVitalSigns(
                vitalSignsObservationId: $observationId,
                actorId: $actorId,
                patientId: $patientId,
                encounterId: $encounterId,
                measurements: $overrides['measurements'] ?? sampleVitals(),
                authoredContext: $overrides['authoredContext']
                    ?? VitalSignsObservation::AUTHORED_CONTEXT_NURSING,
                occurredAt: $overrides['occurredAt'] ?? null,
                reason: $overrides['reason'] ?? null,
            ),
        ));

        return $observationId;
    }

    /**
     * Dispatch `RecordDiagnosis` through the real CommandBus and return the new
     * diagnosis id.
     *
     * @param  array<string, mixed>  $overrides
     */
    function clinicalRecordDiagnosis(
        string $tenantId,
        ?string $facilityId,
        string $patientId,
        string $encounterId,
        string $actorId,
        array $overrides = [],
    ): string {
        $diagnosisId = $overrides['diagnosisId'] ?? (string) Str::uuid7();

        TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
            new RecordDiagnosis(
                diagnosisId: $diagnosisId,
                actorId: $actorId,
                patientId: $patientId,
                encounterId: $encounterId,
                coding: $overrides['coding'] ?? DiagnosisCode::of(
                    code: $overrides['code'] ?? 'B54',
                    codeSystem: $overrides['codeSystem'] ?? 'ICD-10',
                ),
                description: $overrides['description'] ?? 'Unspecified malaria',
                clinicalStatus: $overrides['clinicalStatus']
                    ?? Diagnosis::CLINICAL_STATUS_DEFAULT,
                occurredAt: $overrides['occurredAt'] ?? null,
                reason: $overrides['reason'] ?? null,
            ),
        ));

        return $diagnosisId;
    }

    /**
     * Dispatch `RecordTriageAssessment` through the real CommandBus and return
     * the new triage assessment id (C.9 — ADR-145).
     *
     * @param  array<string, mixed>  $overrides
     */
    function clinicalRecordTriage(
        string $tenantId,
        ?string $facilityId,
        string $patientId,
        string $encounterId,
        string $actorId,
        array $overrides = [],
    ): string {
        $triageAssessmentId = $overrides['triageAssessmentId'] ?? (string) Str::uuid7();

        TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
            new RecordTriageAssessment(
                triageAssessmentId: $triageAssessmentId,
                actorId: $actorId,
                patientId: $patientId,
                encounterId: $encounterId,
                chiefComplaint: $overrides['chiefComplaint'] ?? 'Fever and cough for two days',
                triagePriority: $overrides['triagePriority'] ?? TriagePriority::URGENT,
                acuity: $overrides['acuity'] ?? null,
                consciousness: $overrides['consciousness'] ?? null,
                sourceVitalsObservationId: $overrides['sourceVitalsObservationId'] ?? null,
                authoredContext: $overrides['authoredContext']
                    ?? TriageAssessment::AUTHORED_CONTEXT_NURSING,
                occurredAt: $overrides['occurredAt'] ?? null,
                reason: $overrides['reason'] ?? null,
            ),
        ));

        return $triageAssessmentId;
    }

    /**
     * Define a procedure catalog entry directly via the repository (the
     * console-command write path — ADR-146 D-C) and return its id.
     *
     * @param  array<string, mixed>  $overrides
     */
    function clinicalDefineProcedure(string $tenantId, array $overrides = []): string
    {
        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $overrides): string {
            $definitions = app(ProcedureDefinitionRepository::class);

            $definition = ProcedureDefinition::define(
                id: $overrides['definitionId'] ?? (string) Str::uuid7(),
                tenantId: $tenantId,
                code: $overrides['code'] ?? ('PROC-DEF-'.Str::random(8)),
                name: $overrides['name'] ?? 'Wound Dressing',
                category: $overrides['category'] ?? 'DRESSING_WOUND_CARE',
                description: $overrides['description'] ?? null,
                protocolReference: $overrides['protocolReference'] ?? null,
                requiredCapabilityCode: $overrides['requiredCapabilityCode'] ?? null,
                requiresSite: $overrides['requiresSite'] ?? false,
                requiresLaterality: $overrides['requiresLaterality'] ?? false,
                requiresReadinessCheck: $overrides['requiresReadinessCheck'] ?? false,
                materialRequirements: $overrides['materialRequirements'] ?? null,
            );

            $definitions->save($definition);

            return (string) $definition->id;
        });
    }

    /**
     * Dispatch `RequestProcedure` through the real CommandBus and return the
     * new procedure id (CP.1 — ADR-146). Defines a fresh catalog entry first
     * unless `definitionId` is supplied.
     *
     * @param  array<string, mixed>  $overrides
     */
    function clinicalRequestProcedure(
        string $tenantId,
        ?string $facilityId,
        string $patientId,
        string $encounterId,
        string $actorId,
        array $overrides = [],
    ): string {
        $definitionId = $overrides['definitionId'] ?? clinicalDefineProcedure($tenantId, $overrides['definition'] ?? []);
        $procedureId = $overrides['procedureId'] ?? (string) Str::uuid7();

        TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
            new RequestProcedure(
                procedureId: $procedureId,
                actorId: $actorId,
                patientId: $patientId,
                encounterId: $encounterId,
                definitionId: $definitionId,
                indication: $overrides['indication'] ?? null,
                sourceDiagnosisId: $overrides['sourceDiagnosisId'] ?? null,
                sourceClinicalPlanId: $overrides['sourceClinicalPlanId'] ?? null,
                financialClearanceRef: $overrides['financialClearanceRef'] ?? null,
                authoredContext: $overrides['authoredContext'] ?? ClinicalProcedure::AUTHORED_CONTEXT_CLINICIAN,
                occurredAt: $overrides['occurredAt'] ?? null,
                reason: $overrides['reason'] ?? null,
            ),
        ));

        return $procedureId;
    }
}
