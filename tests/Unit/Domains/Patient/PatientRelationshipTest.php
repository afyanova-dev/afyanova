<?php

declare(strict_types=1);

use App\Domains\Patient\Contracts\Event\PatientRelationshipRecorded;
use App\Domains\Patient\Contracts\Event\PatientRelationshipRemoved;
use App\Domains\Patient\Domain\Enum\RelationshipRole;
use App\Domains\Patient\Domain\Enum\RelationshipStatus;
use App\Domains\Patient\Domain\Exception\RelationshipAlreadyInactive;
use App\Domains\Patient\Domain\Model\PatientRelationship;
use Illuminate\Support\Str;

/*
 | 10-patient-domain.md §35-§38 / ADR-126 — the PatientRelationship aggregate's
 | own invariants, independent of persistence.
 */

function recordedRelationship(RelationshipRole $role = RelationshipRole::EmergencyContact): PatientRelationship
{
    $relationship = PatientRelationship::record(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        role: $role,
        relatedName: 'Neema Mkwawa',
        relationshipLabel: 'sister',
        relatedPhone: '+255700111222',
        note: null,
        recordedByUserId: (string) Str::uuid7(),
    );
    $relationship->releaseEvents();

    return $relationship;
}

test('record() builds an ACTIVE relationship and records PatientRelationshipRecorded', function () {
    $id = (string) Str::uuid7();
    $relationship = PatientRelationship::record(
        id: $id,
        tenantId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        role: RelationshipRole::NextOfKin,
        relatedName: 'Neema Mkwawa',
        relationshipLabel: 'sister',
        relatedPhone: '+255700111222',
        note: 'primary contact',
        recordedByUserId: (string) Str::uuid7(),
    );

    expect((string) $relationship->id)->toBe($id)
        ->and($relationship->role)->toBe(RelationshipRole::NextOfKin)
        ->and($relationship->status)->toBe(RelationshipStatus::Active)
        ->and($relationship->related_name)->toBe('Neema Mkwawa')
        ->and((int) $relationship->version)->toBe(0);

    $events = $relationship->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PatientRelationshipRecorded::class);

    /** @var PatientRelationshipRecorded $event */
    $event = $events[0];
    expect($event->relationshipId)->toBe($id)
        ->and($event->role)->toBe('NEXT_OF_KIN')
        ->and($event->eventId)->toBeUuid();
});

test('deactivate() moves ACTIVE -> INACTIVE, stamps the actor, and records PatientRelationshipRemoved', function () {
    $relationship = recordedRelationship();
    $actorId = (string) Str::uuid7();

    $relationship->deactivate($actorId);

    expect($relationship->status)->toBe(RelationshipStatus::Inactive)
        ->and($relationship->removed_by_user_id)->toBe($actorId)
        ->and($relationship->removed_at)->not->toBeNull();

    $events = $relationship->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PatientRelationshipRemoved::class);
});

test('deactivate() on an already-INACTIVE relationship is rejected (ADR-126 D-8)', function () {
    $relationship = recordedRelationship();
    $relationship->deactivate((string) Str::uuid7());
    $relationship->releaseEvents();

    expect(fn () => $relationship->deactivate((string) Str::uuid7()))
        ->toThrow(RelationshipAlreadyInactive::class);
});

test('RelationshipAlreadyInactive maps to HTTP 409 with a machine code (IC §15)', function () {
    $exception = RelationshipAlreadyInactive::forId((string) Str::uuid7());

    expect($exception->httpStatus())->toBe(409)
        ->and($exception->errorCode())->toBe('relationship_already_inactive');
});

test('neither relationship event carries the related person name or phone (IC §7.1.1)', function () {
    $relationship = PatientRelationship::record(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        role: RelationshipRole::Guardian,
        relatedName: 'Baraka Secret',
        relationshipLabel: 'uncle',
        relatedPhone: '+255700999888',
        note: 'sensitive note text',
        recordedByUserId: (string) Str::uuid7(),
    );
    $relationship->deactivate((string) Str::uuid7());

    foreach ($relationship->releaseEvents() as $event) {
        $payload = (array) $event;
        foreach (['Baraka Secret', '+255700999888', 'sensitive note text', 'uncle'] as $phi) {
            expect($payload)->not->toContain($phi);
        }
    }
});
