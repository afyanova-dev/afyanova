<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Contracts\Dto\EncounterContext;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Domains\Patient\Application\Command\RegisterPatient;
use App\Domains\Patient\Application\Command\RegisterPatientResult;
use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
 | PHASE 5 (E.13) gate — EncounterApi::getEncounterContext (ADR-135). Delegates
 | to the E.12 GetEncounter query: protected (encounter.encounters.read + the
 | GetEncounterHandler facility refinement), maps EncounterSummary -> the five
 | ratified EncounterContext fields.
 */

uses(RefreshDatabase::class);

function e13Fixture(array $permissions = ['encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update', 'patient.patients.create', 'patient.patients.read']): array
{
    $fixture = makeTenantWithUser($permissions, ['OUTPATIENT_CARE']);
    test()->actingAs($fixture['user']);

    return $fixture;
}

function e13RegisterPatient(string $tenantId, string $facilityId, string $actorId): RegisterPatientResult
{
    /** @var RegisterPatientResult */
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(new RegisterPatient(
        patientId: (string) Str::uuid7(), actorId: $actorId, givenName: 'E13', familyName: 'Proof'.Str::random(5),
        middleName: null, displayName: null, sex: Sex::Male, dateOfBirth: '1980-01-01', dobPrecision: DobPrecision::Known,
        primaryPhone: null, email: null,
    )));
}

test('getEncounterContext returns exactly the five ratified fields, mapped from the real encounter', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = e13Fixture();
    $patientId = e13RegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    $encounterId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
    });

    $context = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(EncounterApi::class)->getEncounterContext($encounterId));

    expect($context)->toBeInstanceOf(EncounterContext::class)
        ->and($context->encounterId)->toBe($encounterId)
        ->and($context->patientId)->toBe($patientId)
        ->and($context->facilityId)->toBe((string) $facility->id)
        ->and($context->status)->toBe('ACTIVE')
        ->and($context->encounterType)->toBe('OUTPATIENT');
});

test('an unknown / cross-tenant encounter -> NotFound', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = e13Fixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = e13Fixture();

    $patientB = e13RegisterPatient((string) $tenantB->id, (string) $facilityB->id, (string) $userB->id)->patientId;
    $encounterB = (string) Str::uuid7();
    TenantContextScope::run((string) $tenantB->id, (string) $facilityB->id, fn () => app(CommandBus::class)
        ->dispatch(new CreateEncounter($encounterB, $patientB, (string) $userB->id, EncounterType::Outpatient)));

    test()->actingAs($userA);
    expect(fn () => TenantContextScope::run((string) $tenantA->id, (string) $facilityA->id, fn () => app(EncounterApi::class)->getEncounterContext((string) Str::uuid7())))
        ->toThrow(NotFound::class);
    expect(fn () => TenantContextScope::run((string) $tenantA->id, (string) $facilityA->id, fn () => app(EncounterApi::class)->getEncounterContext($encounterB)))
        ->toThrow(NotFound::class);
});

test('an encounter at a facility outside the caller\'s scope -> NotFound (the GetEncounterHandler refinement)', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = e13Fixture();
    $patientId = e13RegisterPatient((string) $tenant->id, (string) $facilityA->id, (string) $user->id)->patientId;

    $otherFacility = (string) Str::uuid7();
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility($otherFacility)->forPatient($patientId)->create()->id);

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facilityA->id, fn () => app(EncounterApi::class)->getEncounterContext($encounterId)))
        ->toThrow(NotFound::class);
});

test('a caller without encounter.encounters.read is denied', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = e13Fixture(['encounter.encounters.create', 'encounter.encounters.update', 'patient.patients.create', 'patient.patients.read']);
    $patientId = e13RegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient)));

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(EncounterApi::class)->getEncounterContext($encounterId)))
        ->toThrow(AuthorizationException::class);
});
