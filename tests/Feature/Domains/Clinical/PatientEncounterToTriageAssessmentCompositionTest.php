<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectTriageAssessment;
use App\Domains\Clinical\Application\Command\RecordTriageAssessment;
use App\Domains\Clinical\Application\Query\GetEncounterTriageAssessments;
use App\Domains\Clinical\Application\Query\GetTriageAssessment;
use App\Domains\Clinical\Contracts\Dto\TriageAssessmentSummary;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\ValueObject\TriagePriority;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.9) cross-domain gate — the canonical Patient -> Encounter ->
 | TriageAssessment composition (blueprint.md §192; IC §20.2; AGENTS §43). REAL
 | CommandBus / QueryBus / PatientApi / EncounterApi — never mocked (ADR-135).
 | Proves origination is gated at arrival, and re-triage / reads are independent
 | of the encounter's later state.
 */

uses(RefreshDatabase::class);

test('RegisterPatient -> CreateEncounter -> Start -> RecordTriageAssessment -> Get -> re-triage (ungated) -> GetEncounter chain', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();

    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user): void {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
    });

    // triage at presentation
    $v1 = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new RecordTriageAssessment(
        triageAssessmentId: $v1, actorId: (string) $user->id, patientId: $patientId, encounterId: $encounterId,
        chiefComplaint: 'Shortness of breath', triagePriority: TriagePriority::URGENT, acuity: 'ESI-3',
        authoredContext: 'NURSING',
    )));

    /** @var TriageAssessmentSummary $s1 */
    $s1 = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetTriageAssessment($v1)));
    expect($s1->triagePriority)->toBe('URGENT')
        ->and($s1->patientId)->toBe($patientId)
        ->and($s1->encounterId)->toBe($encounterId)
        ->and($s1->facilityId)->toBe((string) $facility->id)
        ->and($s1->supersededAt)->toBeNull();

    // patient deteriorates -> re-triage (a NEW superseding assessment)
    $v2 = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new CorrectTriageAssessment(
        newTriageAssessmentId: $v2, supersedesTriageAssessmentId: $v1, actorId: (string) $user->id,
        chiefComplaint: 'Shortness of breath, now with cyanosis', triagePriority: TriagePriority::EMERGENCY,
        reason: 'SpO2 dropped to 84% on room air',
    )));

    /** @var list<TriageAssessmentSummary> $chain */
    $chain = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)
        ->dispatch(new GetEncounterTriageAssessments($encounterId)));
    expect($chain)->toHaveCount(2);

    /** @var list<TriageAssessmentSummary> $current */
    $current = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)
        ->dispatch(new GetEncounterTriageAssessments($encounterId, true)));
    expect($current)->toHaveCount(1)
        ->and($current[0]->triageAssessmentId)->toBe($v2)
        ->and($current[0]->triagePriority)->toBe('EMERGENCY');

    // the referenced patient + encounter really exist (no cross-domain FK, but the references are real)
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->exists()))->toBeTrue()
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->exists()))->toBeTrue();
});

test('RecordTriageAssessment against a PLANNED (not-yet-arrived) encounter is rejected — origination requires attendance (ADR-135)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient, planned: true)));

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('PLANNED');

    expect(fn () => clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(EncounterNotEligibleForClinicalRecord::class);
});
