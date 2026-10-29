<?php

declare(strict_types=1);

use App\Domains\Patient\Application\Command\UpdatePatientAddress;
use App\Domains\Patient\Application\Command\UpdatePatientAddressResult;
use App\Domains\Patient\Contracts\Event\PatientAddressUpdated;
use App\Domains\Patient\Domain\Enum\AddressType;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 5 (increment A.4c) gate — UpdatePatientAddress upsert end-to-end
 | through the real CommandBus pipeline (10-patient-domain.md §33-§34; ADR-126).
 | One command covers create / revise / deactivate; a move is a new row +
 | deactivate old (§34); pat_patients is never touched.
 */

uses(RefreshDatabase::class);

/**
 * @return array{tenant: mixed, facility: mixed, user: mixed, patientId: string}
 */
function addressFixture(array $permissions = ['patient.patients.update']): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser($permissions);
    test()->actingAs($user);

    $patientId = TenantContextScope::run(
        (string) $tenant->id,
        (string) $facility->id,
        fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create(['is_provisional' => true])->id,
    );

    return ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId];
}

function dispatchUpdateAddress(string $tenantId, string $facilityId, UpdatePatientAddress $command): UpdatePatientAddressResult
{
    /** @var UpdatePatientAddressResult */
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

test('UpdatePatientAddress (create branch): stores an ACTIVE row, PHI-free event + audit; pat_patients untouched', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = addressFixture();
    $addressId = (string) Str::uuid7();

    $result = dispatchUpdateAddress((string) $tenant->id, (string) $facility->id, new UpdatePatientAddress(
        addressId: $addressId,
        patientId: $patientId,
        actorId: (string) $user->id,
        type: AddressType::Residential,
        region: 'Dar es Salaam',
        district: 'Kinondoni',
        ward: 'Mikocheni',
        streetOrVillage: '10 Msasani Rd',
    ));

    expect($result->wasCreated)->toBeTrue();

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_addresses')->where('id', $addressId)->first());
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('ACTIVE')
        ->and($row->type)->toBe('RESIDENTIAL')
        ->and($row->ward)->toBe('Mikocheni')
        ->and((int) $row->version)->toBe(0);

    // address changes have NO effect on is_provisional (ADR-119)
    $patient = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->first());
    expect((bool) $patient->is_provisional)->toBeTrue();

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PatientAddressUpdated::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('patient.PatientAddressUpdated');
    $payload = json_decode((string) $outbox->payload, true);
    expect($payload)->not->toContain('10 Msasani Rd')
        ->and($payload)->not->toContain('Mikocheni');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'patient.address_updated')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->target_id)->toBe($patientId)
        ->and($audit->metadata)->not->toContain('10 Msasani Rd')
        ->and($audit->metadata)->not->toContain('Mikocheni');
});

test('UpdatePatientAddress (update branch): corrects the same addressId in place, bumps version', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = addressFixture();
    $addressId = (string) Str::uuid7();

    $first = dispatchUpdateAddress((string) $tenant->id, (string) $facility->id, new UpdatePatientAddress(
        addressId: $addressId, patientId: $patientId, actorId: (string) $user->id,
        type: AddressType::Residential, region: 'Dar es Salaam', ward: 'Mikcheni', // typo
    ));
    $second = dispatchUpdateAddress((string) $tenant->id, (string) $facility->id, new UpdatePatientAddress(
        addressId: $addressId, patientId: $patientId, actorId: (string) $user->id,
        type: AddressType::Residential, region: 'Dar es Salaam', ward: 'Mikocheni', // corrected
    ));

    expect($first->wasCreated)->toBeTrue()
        ->and($second->wasCreated)->toBeFalse();

    $count = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_addresses')->count());
    expect($count)->toBe(1);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_addresses')->where('id', $addressId)->first());
    expect($row->ward)->toBe('Mikocheni')
        ->and((int) $row->version)->toBe(1);
});

test('a MOVE is a new address row while the old one is deactivated (§34 — nothing erased)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = addressFixture();

    $oldId = (string) Str::uuid7();
    $newId = (string) Str::uuid7();

    dispatchUpdateAddress((string) $tenant->id, (string) $facility->id, new UpdatePatientAddress(
        addressId: $oldId, patientId: $patientId, actorId: (string) $user->id,
        type: AddressType::Residential, region: 'Mwanza', ward: 'Nyamagana',
    ));
    // patient moves: record new address, deactivate the old one
    dispatchUpdateAddress((string) $tenant->id, (string) $facility->id, new UpdatePatientAddress(
        addressId: $newId, patientId: $patientId, actorId: (string) $user->id,
        type: AddressType::Residential, region: 'Dar es Salaam', ward: 'Mikocheni',
    ));
    dispatchUpdateAddress((string) $tenant->id, (string) $facility->id, new UpdatePatientAddress(
        addressId: $oldId, patientId: $patientId, actorId: (string) $user->id,
        type: AddressType::Residential, region: 'Mwanza', ward: 'Nyamagana', isActive: false,
    ));

    $rows = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_addresses')
        ->where('patient_id', $patientId)->orderBy('id')->get()->keyBy('id'));
    expect($rows)->toHaveCount(2)
        ->and($rows[$newId]->status)->toBe('ACTIVE')
        ->and($rows[$oldId]->status)->toBe('INACTIVE')
        ->and($rows[$oldId]->region)->toBe('Mwanza'); // old address data still there
});

test('UpdatePatientAddress is rejected for a missing / cross-tenant patient', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = addressFixture();

    expect(fn () => dispatchUpdateAddress((string) $tenant->id, (string) $facility->id, new UpdatePatientAddress(
        addressId: (string) Str::uuid7(), patientId: (string) Str::uuid7(), actorId: (string) $user->id,
        type: AddressType::Residential, region: 'Dar es Salaam',
    )))->toThrow(NotFound::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_addresses')->count()))->toBe(0);
});

test('UpdatePatientAddress rejects an addressId that belongs to a different patient', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientA] = addressFixture();
    $patientB = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => (string) Patient::factory()->forTenant((string) $tenant->id)->create()->id);
    $addressId = (string) Str::uuid7();

    dispatchUpdateAddress((string) $tenant->id, (string) $facility->id, new UpdatePatientAddress(
        addressId: $addressId, patientId: $patientA, actorId: (string) $user->id,
        type: AddressType::Residential, region: 'Dar es Salaam',
    ));

    expect(fn () => dispatchUpdateAddress((string) $tenant->id, (string) $facility->id, new UpdatePatientAddress(
        addressId: $addressId, patientId: $patientB, actorId: (string) $user->id,
        type: AddressType::Residential, region: 'Mwanza',
    )))->toThrow(NotFound::class);
});

test('UpdatePatientAddress is denied without patient.patients.update (default deny)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = addressFixture([]);

    expect(fn () => dispatchUpdateAddress((string) $tenant->id, (string) $facility->id, new UpdatePatientAddress(
        addressId: (string) Str::uuid7(), patientId: $patientId, actorId: (string) $user->id,
        type: AddressType::Residential, region: 'Dar es Salaam',
    )))->toThrow(AuthorizationException::class);
});

test('a patient may hold both a RESIDENTIAL and a POSTAL address (no invented cardinality)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'patientId' => $patientId] = addressFixture();

    dispatchUpdateAddress((string) $tenant->id, (string) $facility->id, new UpdatePatientAddress(
        addressId: (string) Str::uuid7(), patientId: $patientId, actorId: (string) $user->id,
        type: AddressType::Residential, region: 'Dar es Salaam', ward: 'Mikocheni',
    ));
    dispatchUpdateAddress((string) $tenant->id, (string) $facility->id, new UpdatePatientAddress(
        addressId: (string) Str::uuid7(), patientId: $patientId, actorId: (string) $user->id,
        type: AddressType::Postal, postalCode: 'P.O. Box 456',
    ));

    $count = Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patient_addresses')->where('patient_id', $patientId)->count());
    expect($count)->toBe(2);
});

test('UpdatePatientAddress rejects an all-blank address and malformed ids (IC §3)', function () {
    expect(fn () => new UpdatePatientAddress((string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7(), AddressType::Residential))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new UpdatePatientAddress('nope', (string) Str::uuid7(), (string) Str::uuid7(), AddressType::Residential, region: 'Dar'))
        ->toThrow(InvalidArgumentException::class);
});
