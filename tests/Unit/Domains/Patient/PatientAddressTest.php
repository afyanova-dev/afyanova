<?php

declare(strict_types=1);

use App\Domains\Patient\Contracts\Event\PatientAddressUpdated;
use App\Domains\Patient\Domain\Enum\AddressStatus;
use App\Domains\Patient\Domain\Enum\AddressType;
use App\Domains\Patient\Domain\Model\PatientAddress;
use Illuminate\Support\Str;

/*
 | 10-patient-domain.md §33-§34 / ADR-126 — the PatientAddress aggregate's own
 | invariants, independent of persistence.
 */

function openedAddress(AddressType $type = AddressType::Residential): PatientAddress
{
    $address = PatientAddress::open(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        type: $type,
        region: 'Dar es Salaam',
        district: 'Kinondoni',
        ward: 'Mikocheni',
        streetOrVillage: 'Msasani',
        postalCode: null,
        notes: null,
        recordedByUserId: (string) Str::uuid7(),
    );
    $address->releaseEvents();

    return $address;
}

test('open() builds an ACTIVE address and records PatientAddressUpdated', function () {
    $id = (string) Str::uuid7();
    $address = PatientAddress::open(
        id: $id,
        tenantId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        type: AddressType::Postal,
        region: 'Mwanza',
        district: null,
        ward: null,
        streetOrVillage: null,
        postalCode: 'P.O. Box 123',
        notes: null,
        recordedByUserId: (string) Str::uuid7(),
    );

    expect((string) $address->id)->toBe($id)
        ->and($address->type)->toBe(AddressType::Postal)
        ->and($address->postal_code)->toBe('P.O. Box 123')
        ->and($address->status)->toBe(AddressStatus::Active)
        ->and((int) $address->version)->toBe(0);

    $events = $address->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PatientAddressUpdated::class);

    /** @var PatientAddressUpdated $event */
    $event = $events[0];
    expect($event->addressId)->toBe($id)
        ->and($event->addressType)->toBe('POSTAL')
        ->and($event->isActive)->toBeTrue();
});

test('revise() corrects fields in place and records another PatientAddressUpdated', function () {
    $address = openedAddress();

    $address->revise(AddressType::Residential, 'Dar es Salaam', 'Kinondoni', 'Kawe', 'Kawe Beach', null, 'near the school', true, (string) Str::uuid7());

    expect($address->ward)->toBe('Kawe')
        ->and($address->street_or_village)->toBe('Kawe Beach')
        ->and($address->notes)->toBe('near the school')
        ->and($address->status)->toBe(AddressStatus::Active);

    expect($address->releaseEvents())->toHaveCount(1);
});

test('revise() with isActive=false deactivates the address (§34 — row kept)', function () {
    $address = openedAddress();
    $actorId = (string) Str::uuid7();

    $address->revise(AddressType::Residential, 'Dar es Salaam', 'Kinondoni', 'Mikocheni', 'Msasani', null, null, false, $actorId);

    expect($address->status)->toBe(AddressStatus::Inactive)
        ->and($address->removed_by_user_id)->toBe($actorId)
        ->and($address->removed_at)->not->toBeNull();

    /** @var PatientAddressUpdated $event */
    $event = $address->releaseEvents()[0];
    expect($event->isActive)->toBeFalse();
});

test('no PatientAddressUpdated event carries any §33 address value (IC §7.1.1)', function () {
    $address = PatientAddress::open(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        type: AddressType::Residential,
        region: 'SecretRegion',
        district: 'SecretDistrict',
        ward: 'SecretWard',
        streetOrVillage: '10 Secret Street',
        postalCode: 'BOX-SECRET',
        notes: 'sensitive note',
        recordedByUserId: (string) Str::uuid7(),
    );
    $address->revise(AddressType::Residential, 'SecretRegion', 'SecretDistrict', 'SecretWard', '10 Secret Street', 'BOX-SECRET', 'sensitive note', false, (string) Str::uuid7());

    foreach ($address->releaseEvents() as $event) {
        $payload = (array) $event;
        foreach (['SecretRegion', 'SecretDistrict', 'SecretWard', '10 Secret Street', 'BOX-SECRET', 'sensitive note'] as $phi) {
            expect($payload)->not->toContain($phi);
        }
    }
});
