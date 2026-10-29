<?php

declare(strict_types=1);

use App\Domains\Patient\Contracts\Event\PatientUpdated;
use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\Model\Patient;
use Illuminate\Support\Str;

/*
 | 10-patient-domain.md §26, §60 / ADR-127 — Patient::reviseDemographics: the
 | partial-patch behaviour, display_name derivation and is_provisional
 | auto-clear on the aggregate itself, independent of persistence.
 */

function patientFor(array $overrides = []): Patient
{
    $patient = Patient::register(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        patientNumber: 'PAT-00000001',
        facilityId: (string) Str::uuid7(),
        givenName: $overrides['givenName'] ?? 'Asha',
        familyName: $overrides['familyName'] ?? 'Mkwawa',
        middleName: $overrides['middleName'] ?? 'B',
        displayName: $overrides['displayName'] ?? 'Asha B Mkwawa',
        sex: $overrides['sex'] ?? Sex::Female,
        dateOfBirth: $overrides['dateOfBirth'] ?? '1990-05-01',
        dobPrecision: $overrides['dobPrecision'] ?? DobPrecision::Known,
        primaryPhone: $overrides['primaryPhone'] ?? '+255700000000',
        email: $overrides['email'] ?? 'asha@example.test',
        provisional: $overrides['provisional'] ?? false,
        source: 'RECEPTION',
        registeredByUserId: (string) Str::uuid7(),
    );
    $patient->releaseEvents();
    // simulate a persisted row so getDirty/getOriginal baselines are set
    $patient->syncOriginal();

    return $patient;
}

test('a single-field patch changes only that field', function () {
    $patient = patientFor();

    $diff = $patient->reviseDemographics(['primary_phone' => '+255711111111']);

    expect($patient->primary_phone)->toBe('+255711111111')
        ->and($patient->family_name)->toBe('Mkwawa')          // untouched
        ->and($patient->email)->toBe('asha@example.test')     // untouched
        ->and($diff['changedFields'])->toBe(['primary_phone'])
        ->and($diff['previous'])->toBe(['primary_phone' => '+255700000000'])
        ->and($diff['new'])->toBe(['primary_phone' => '+255711111111']);
});

test('a multi-field patch changes exactly the supplied fields', function () {
    $patient = patientFor();

    $diff = $patient->reviseDemographics([
        'family_name' => 'Kimaro',
        'sex' => 'MALE',
        'email' => 'a.kimaro@example.test',
    ]);

    expect($patient->family_name)->toBe('Kimaro')
        ->and($patient->sex)->toBe(Sex::Male)
        ->and($patient->email)->toBe('a.kimaro@example.test')
        ->and(sortedList($diff['changedFields']))->toBe(['display_name', 'email', 'family_name', 'sex']); // display re-derived
});

test('an omitted field keeps its existing value', function () {
    $patient = patientFor();
    $patient->reviseDemographics(['given_name' => 'Aisha']);

    expect($patient->family_name)->toBe('Mkwawa')
        ->and($patient->middle_name)->toBe('B')
        ->and($patient->date_of_birth?->toDateString())->toBe('1990-05-01');
});

test('an explicit null clears a nullable field', function () {
    $patient = patientFor();

    $diff = $patient->reviseDemographics(['middle_name' => null, 'email' => null]);

    expect($patient->middle_name)->toBeNull()
        ->and($patient->email)->toBeNull()
        ->and(sortedList($diff['changedFields']))->toBe(['email', 'middle_name'])
        ->and($diff['new'])->toBe(['middle_name' => null, 'email' => null]);
});

test('display_name is re-derived from the post-patch name parts when not supplied', function () {
    $patient = patientFor();

    $patient->reviseDemographics(['given_name' => 'Aisha', 'family_name' => 'Kimaro']);

    expect($patient->display_name)->toBe('Aisha Kimaro');
});

test('an explicitly supplied display_name is used verbatim even when name parts change', function () {
    $patient = patientFor();

    $patient->reviseDemographics([
        'given_name' => 'Aisha',
        'display_name' => 'Dr. Aisha Kimaro',
    ]);

    expect($patient->display_name)->toBe('Dr. Aisha Kimaro');
});

test('a provisional patient becomes non-provisional once the four-field rule is met (ADR-127 rule 1)', function () {
    $patient = patientFor([
        'givenName' => 'Unknown', 'familyName' => 'Patient', 'displayName' => 'Unknown Patient',
        'sex' => Sex::Unknown, 'dateOfBirth' => null, 'dobPrecision' => DobPrecision::Unknown,
        'primaryPhone' => null, 'email' => null, 'provisional' => true,
    ]);

    $diff = $patient->reviseDemographics([
        'given_name' => 'Neema',
        'family_name' => 'Mushi',
        'sex' => 'FEMALE',
        'date_of_birth' => '1988-02-02',
        'dob_precision' => 'KNOWN',
    ]);

    expect($patient->is_provisional)->toBeFalse()
        ->and($diff['provisionalCleared'])->toBeTrue();
});

test('an incomplete patch leaves a provisional patient provisional', function () {
    $patient = patientFor([
        'givenName' => 'Unknown', 'familyName' => 'Patient', 'displayName' => 'Unknown Patient',
        'sex' => Sex::Unknown, 'dateOfBirth' => null, 'dobPrecision' => DobPrecision::Unknown,
        'provisional' => true,
    ]);

    $diff = $patient->reviseDemographics(['given_name' => 'Neema', 'family_name' => 'Mushi']); // still no sex / dob

    expect($patient->is_provisional)->toBeTrue()
        ->and($diff['provisionalCleared'])->toBeFalse();
});

test('an already non-provisional patient stays non-provisional and provisionalCleared is false', function () {
    $patient = patientFor(); // complete, not provisional

    $diff = $patient->reviseDemographics(['family_name' => 'Kimaro']);

    expect($patient->is_provisional)->toBeFalse()
        ->and($diff['provisionalCleared'])->toBeFalse();
});

test('PatientUpdated carries coded changed-field names and provisionalCleared, and no PHI', function () {
    $patient = patientFor();

    $patient->reviseDemographics([
        'family_name' => 'Kimaro',
        'date_of_birth' => '1991-06-06',
        'primary_phone' => '+255722222222',
    ]);

    $events = $patient->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PatientUpdated::class);

    /** @var PatientUpdated $event */
    $event = $events[0];
    expect(sortedList($event->changedFields))->toBe(['date_of_birth', 'display_name', 'family_name', 'primary_phone'])
        ->and($event->provisionalCleared)->toBeFalse();

    $payload = (array) $event;
    foreach (['Kimaro', '1991-06-06', '+255722222222', 'Asha', 'asha@example.test'] as $phi) {
        expect($payload)->not->toContain($phi);
    }
});

test('a no-op patch (same value) records no event and reports no change', function () {
    $patient = patientFor();

    $diff = $patient->reviseDemographics(['given_name' => 'Asha', 'email' => 'asha@example.test']);

    expect($diff['changedFields'])->toBe([])
        ->and($diff['provisionalCleared'])->toBeFalse()
        ->and($patient->releaseEvents())->toBe([]);
});

function sortedList(array $values): array
{
    sort($values);

    return $values;
}
