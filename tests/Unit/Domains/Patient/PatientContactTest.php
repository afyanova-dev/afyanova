<?php

declare(strict_types=1);

use App\Domains\Patient\Contracts\Event\PatientContactUpdated;
use App\Domains\Patient\Domain\Enum\ContactStatus;
use App\Domains\Patient\Domain\Enum\ContactType;
use App\Domains\Patient\Domain\Model\PatientContact;
use Illuminate\Support\Str;

/*
 | 10-patient-domain.md §31-§32 / ADR-126 — the PatientContact aggregate's own
 | invariants, independent of persistence.
 */

function openedContact(ContactType $type = ContactType::Mobile, string $value = '+255 700 111 222'): PatientContact
{
    $contact = PatientContact::open(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        type: $type,
        value: $value,
        label: 'secondary',
        isPreferred: false,
        recordedByUserId: (string) Str::uuid7(),
    );
    $contact->releaseEvents();

    return $contact;
}

test('normalize() folds an email to lower-case and a phone to digits', function () {
    expect(PatientContact::normalize(ContactType::Email, '  Asha@Example.TEST '))->toBe('asha@example.test')
        ->and(PatientContact::normalize(ContactType::Mobile, '+255 700-111 222'))->toBe('255700111222')
        ->and(PatientContact::normalize(ContactType::Landline, '(022) 213 0000'))->toBe('0222130000')
        ->and(PatientContact::normalize(ContactType::Other, '  skype: asha  '))->toBe('skype: asha');
});

test('open() builds an ACTIVE contact, normalizes the value, and records PatientContactUpdated', function () {
    $id = (string) Str::uuid7();
    $contact = PatientContact::open(
        id: $id,
        tenantId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        type: ContactType::Email,
        value: 'Asha@Example.test',
        label: 'work',
        isPreferred: true,
        recordedByUserId: (string) Str::uuid7(),
    );

    expect((string) $contact->id)->toBe($id)
        ->and($contact->type)->toBe(ContactType::Email)
        ->and($contact->normalized_value)->toBe('asha@example.test')
        ->and($contact->is_preferred)->toBeTrue()
        ->and($contact->status)->toBe(ContactStatus::Active)
        ->and((int) $contact->version)->toBe(0);

    $events = $contact->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PatientContactUpdated::class);

    /** @var PatientContactUpdated $event */
    $event = $events[0];
    expect($event->contactId)->toBe($id)
        ->and($event->contactType)->toBe('EMAIL')
        ->and($event->isActive)->toBeTrue();
});

test('revise() updates fields and records another PatientContactUpdated', function () {
    $contact = openedContact();

    $contact->revise(ContactType::Landline, '022 213 0000', 'home', true, true, (string) Str::uuid7());

    expect($contact->type)->toBe(ContactType::Landline)
        ->and($contact->normalized_value)->toBe('0222130000')
        ->and($contact->label)->toBe('home')
        ->and($contact->is_preferred)->toBeTrue()
        ->and($contact->status)->toBe(ContactStatus::Active);

    expect($contact->releaseEvents())->toHaveCount(1);
});

test('revise() with isActive=false deactivates the contact (ADR-126 D-8) and the event reflects it', function () {
    $contact = openedContact();
    $actorId = (string) Str::uuid7();

    $contact->revise(ContactType::Mobile, '+255700111222', 'secondary', false, false, $actorId);

    expect($contact->status)->toBe(ContactStatus::Inactive)
        ->and($contact->removed_by_user_id)->toBe($actorId)
        ->and($contact->removed_at)->not->toBeNull();

    /** @var PatientContactUpdated $event */
    $event = $contact->releaseEvents()[0];
    expect($event->isActive)->toBeFalse();
});

test('revise() with isActive=true reactivates a deactivated contact', function () {
    $contact = openedContact();
    $contact->revise(ContactType::Mobile, '+255700111222', null, false, false, (string) Str::uuid7());
    $contact->releaseEvents();

    $contact->revise(ContactType::Mobile, '+255700111222', null, false, true, (string) Str::uuid7());

    expect($contact->status)->toBe(ContactStatus::Active)
        ->and($contact->removed_at)->toBeNull()
        ->and($contact->removed_by_user_id)->toBeNull();
});

test('no PatientContactUpdated event carries the contact value or label (IC §7.1.1)', function () {
    $contact = PatientContact::open(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        type: ContactType::Email,
        value: 'secret.person@example.test',
        label: 'sensitive label',
        isPreferred: false,
        recordedByUserId: (string) Str::uuid7(),
    );
    $contact->revise(ContactType::Email, 'secret.person@example.test', 'sensitive label', false, false, (string) Str::uuid7());

    foreach ($contact->releaseEvents() as $event) {
        $payload = (array) $event;
        expect($payload)->not->toContain('secret.person@example.test')
            ->and($payload)->not->toContain('sensitive label');
    }
});
