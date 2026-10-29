<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Application\Command\CompleteEncounter;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Application\Query\GetEncounter;
use App\Domains\Encounter\Contracts\Dto\EncounterSummary;
use App\Domains\Encounter\Contracts\Event\EncounterCompleted;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Patient\Application\Command\RegisterPatient;
use App\Domains\Patient\Application\Command\RegisterPatientResult;
use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 5 (E.12) cross-domain gate — the canonical Patient → Encounter
 | composition (blueprint.md §23 "Register patient / Create attendance /
 | Complete attendance"; IC §20.2, §21 step 13; AGENTS.md §43). Real CommandBus,
 | real QueryBus, real PatientApi (consumed by CreateEncounter), real Encounter
 | lifecycle, real EncounterReader — the cross-domain contract is NOT mocked.
 |
 | Duplicate-delivery for the async event contract is already proven by
 | tests/Feature/CrossDomain/RepresentativeContractProofTest.php (PHASE 4 gate);
 | this synchronous command chain does not re-test it.
 */

uses(RefreshDatabase::class);

/**
 * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed}
 */
function compositionFixture(): array
{
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.create',
        'patient.patients.read',
        'encounter.encounters.create',
        'encounter.encounters.update',
        'encounter.encounters.manage_disposition',
        'encounter.encounters.complete',
        'encounter.encounters.read',
    ], ['OUTPATIENT_CARE']);
    test()->actingAs($user);

    return ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function registerCompositionPatient(string $tenantId, string $facilityId, string $actorId, array $overrides = []): RegisterPatientResult
{
    $command = new RegisterPatient(
        patientId: $overrides['patientId'] ?? (string) Str::uuid7(),
        actorId: $actorId,
        givenName: $overrides['givenName'] ?? 'Composition',
        familyName: $overrides['familyName'] ?? ('Proof'.Str::random(6)),
        middleName: null,
        displayName: null,
        sex: Sex::Female,
        dateOfBirth: $overrides['dateOfBirth'] ?? '1988-03-14',
        dobPrecision: DobPrecision::Known,
        primaryPhone: null,
        email: null,
    );

    /** @var RegisterPatientResult */
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

// ── the canonical composition ───────────────────────────────────────

test('RegisterPatient -> CreateEncounter -> StartEncounter -> ChangeEncounterDisposition(DISCHARGED) -> CompleteEncounter -> GetEncounter', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = compositionFixture();

    // 1. Register a patient (Patient domain).
    $registration = registerCompositionPatient((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $patientId = $registration->patientId;

    // Gate: registering a patient alone does NOT create an encounter.
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('patient_id', $patientId)->count()))->toBe(0);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->exists()))->toBeTrue();

    // 2..5. Create the attendance and drive it to completion (Encounter domain,
    //       consuming PatientApi::getPatientSummary inside CreateEncounter).
    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
        $bus->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });

    // exactly one encounter for this patient
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('patient_id', $patientId)->count()))->toBe(1);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($row->patient_id)->toBe($patientId)                 // references the registered patient
        ->and($row->tenant_id)->toBe((string) $tenant->id)     // expected tenant
        ->and($row->facility_id)->toBe((string) $facility->id) // expected facility
        ->and($row->encounter_number)->toMatch('/^ENC-\d{8}$/') // number allocated (ADR-115)
        ->and($row->status)->toBe('COMPLETED')
        ->and($row->disposition)->toBe('DISCHARGED')
        ->and($row->completed_at)->not->toBeNull()
        // CreateEncounter starts a fresh aggregate at version 0; Start / ChangeDisposition
        // / Complete are three subsequent versioned saves — correct increment behaviour.
        ->and((int) $row->version)->toBe(3);

    // 6. Read it back through the real QueryBus / GetEncounter.
    /** @var EncounterSummary $summary */
    $summary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetEncounter($encounterId)));

    expect($summary)->toBeInstanceOf(EncounterSummary::class)
        ->and($summary->encounterId)->toBe($encounterId)
        ->and($summary->patientId)->toBe($patientId)          // GetEncounter returns the same patient id
        ->and($summary->facilityId)->toBe((string) $facility->id)
        ->and($summary->status)->toBe('COMPLETED')
        ->and($summary->disposition)->toBe('DISCHARGED')
        ->and($summary->completedAt)->not->toBeNull()
        ->and($summary->encounterNumber)->toBe($row->encounter_number);

    // EncounterCompleted on the outbox (the expected downstream contract).
    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', EncounterCompleted::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('encounter.EncounterCompleted')
        ->and($outbox->tenant_id)->toBe((string) $tenant->id);
    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload['encounterId'])->toBe($encounterId)
        ->and($payload['patientId'])->toBe($patientId)
        ->and($payload['disposition'])->toBe('DISCHARGED');
});

// ── PatientApi negative paths (existing contract — not modified) ─────

test('CreateEncounter cannot create an encounter for an unknown patient id (NotFound)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = compositionFixture();

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new CreateEncounter((string) Str::uuid7(), (string) Str::uuid7(), (string) $user->id, EncounterType::Outpatient))))
        ->toThrow(NotFound::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->count()))->toBe(0);
});

test('CreateEncounter cannot reference a patient from another tenant (NotFound, RLS)', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = compositionFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = compositionFixture();

    // register the patient in tenant B
    $patientB = registerCompositionPatient((string) $tenantB->id, (string) $facilityB->id, (string) $userB->id)->patientId;

    // tenant A cannot see it → CreateEncounter's PatientApi lookup fails closed
    test()->actingAs($userA);
    expect(fn () => TenantContextScope::run((string) $tenantA->id, (string) $facilityA->id, fn () => app(CommandBus::class)
        ->dispatch(new CreateEncounter((string) Str::uuid7(), $patientB, (string) $userA->id, EncounterType::Outpatient))))
        ->toThrow(NotFound::class);

    expect(Tenancy::run((string) $tenantA->id, fn () => DB::table('enc_encounters')->count()))->toBe(0);
});
