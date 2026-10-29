<?php

declare(strict_types=1);

use App\Domains\Patient\Contracts\Event\PatientMerged;
use App\Domains\Patient\Contracts\Event\PatientUnmerged;
use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\MergeHistoryStatus;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\Exception\MergedPatientIsImmutable;
use App\Domains\Patient\Domain\Exception\PatientMergeRejected;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Patient\Domain\Model\PatientIdentityHistory;
use Illuminate\Support\Str;

/*
 | 10-patient-domain.md §21-§25 / ADR-118 / ADR-128 — the Patient merge /
 | restore transitions and the PatientIdentityHistory ACTIVE -> REVERSED
 | lifecycle, on the aggregates themselves.
 */

function mergeablePatient(PatientStatus $status = PatientStatus::Active): Patient
{
    $patient = Patient::register(
        id: (string) Str::uuid7(), tenantId: (string) Str::uuid7(), patientNumber: 'PAT-00000001',
        facilityId: (string) Str::uuid7(), givenName: 'Asha', familyName: 'Mkwawa', middleName: null,
        displayName: 'Asha Mkwawa', sex: Sex::Female, dateOfBirth: '1990-05-01', dobPrecision: DobPrecision::Known,
        primaryPhone: null, email: null, provisional: false, source: 'RECEPTION', registeredByUserId: (string) Str::uuid7(),
    );
    $patient->releaseEvents();
    if ($status === PatientStatus::Inactive) {
        $patient->markInactive();
        $patient->releaseEvents();
    }

    return $patient;
}

test('Patient::merge() moves the non-surviving patient to MERGED and records PatientMerged', function () {
    $patient = mergeablePatient();
    $survivorId = (string) Str::uuid7();
    $mergeId = (string) Str::uuid7();

    $patient->merge($survivorId, $mergeId);

    expect($patient->status)->toBe(PatientStatus::Merged);

    /** @var PatientMerged $event */
    $event = $patient->releaseEvents()[0];
    expect($event)->toBeInstanceOf(PatientMerged::class)
        ->and($event->survivingPatientId)->toBe($survivorId)
        ->and($event->nonSurvivingPatientId)->toBe((string) $patient->id)
        ->and($event->mergeId)->toBe($mergeId);
});

test('Patient::merge() also works from INACTIVE', function () {
    $patient = mergeablePatient(PatientStatus::Inactive);
    $patient->merge((string) Str::uuid7(), (string) Str::uuid7());
    expect($patient->status)->toBe(PatientStatus::Merged);
});

test('Patient::merge() is rejected from DECEASED or MERGED', function () {
    $deceased = mergeablePatient();
    $deceased->markDeceased();
    $deceased->releaseEvents();
    expect(fn () => $deceased->merge((string) Str::uuid7(), (string) Str::uuid7()))
        ->toThrow(PatientMergeRejected::class);

    $merged = mergeablePatient();
    $merged->merge((string) Str::uuid7(), (string) Str::uuid7());
    $merged->releaseEvents();
    expect(fn () => $merged->merge((string) Str::uuid7(), (string) Str::uuid7()))
        ->toThrow(PatientMergeRejected::class);
});

test('Patient::restoreFromMerge() restores the exact prior status and records PatientUnmerged', function () {
    $patient = mergeablePatient(PatientStatus::Inactive);
    $survivorId = (string) Str::uuid7();
    $mergeId = (string) Str::uuid7();
    $patient->merge($survivorId, $mergeId);
    $patient->releaseEvents();

    $patient->restoreFromMerge(PatientStatus::Inactive, $survivorId, $mergeId);

    expect($patient->status)->toBe(PatientStatus::Inactive);

    /** @var PatientUnmerged $event */
    $event = $patient->releaseEvents()[0];
    expect($event)->toBeInstanceOf(PatientUnmerged::class)
        ->and($event->survivingPatientId)->toBe($survivorId)
        ->and($event->mergeId)->toBe($mergeId);
});

test('Patient::restoreFromMerge() is rejected when the patient is not MERGED', function () {
    $patient = mergeablePatient();
    expect(fn () => $patient->restoreFromMerge(PatientStatus::Active, (string) Str::uuid7(), (string) Str::uuid7()))
        ->toThrow(PatientMergeRejected::class);
});

test('Patient::assertMutable() throws only for a MERGED patient', function () {
    $active = mergeablePatient();
    $active->assertMutable(); // no throw

    $inactive = mergeablePatient(PatientStatus::Inactive);
    $inactive->assertMutable(); // no throw

    $merged = mergeablePatient();
    $merged->merge((string) Str::uuid7(), (string) Str::uuid7());
    expect(fn () => $merged->assertMutable())->toThrow(MergedPatientIsImmutable::class);
});

test('PatientMerged / PatientUnmerged carry no patient number or PHI (§94, IC §7.1.1)', function () {
    $patient = mergeablePatient();
    $survivorId = (string) Str::uuid7();
    $mergeId = (string) Str::uuid7();
    $patient->merge($survivorId, $mergeId);
    $patient->restoreFromMerge(PatientStatus::Active, $survivorId, $mergeId);

    foreach ($patient->releaseEvents() as $event) {
        $payload = (array) $event;
        foreach (['PAT-00000001', 'Asha', 'Mkwawa', '1990-05-01'] as $phi) {
            expect($payload)->not->toContain($phi);
        }
    }
});

test('PatientIdentityHistory::record() opens an ACTIVE row; reverse() flips it to REVERSED once', function () {
    $history = PatientIdentityHistory::record(
        id: (string) Str::uuid7(), tenantId: (string) Str::uuid7(), mergeId: (string) Str::uuid7(),
        survivingPatientId: (string) Str::uuid7(), nonSurvivingPatientId: (string) Str::uuid7(),
        nonSurvivingPatientNumber: 'PAT-00000009', nonSurvivingPriorStatus: PatientStatus::Inactive,
        reason: 'confirmed duplicate', evidence: 'NIDA + DOB', mergedByUserId: (string) Str::uuid7(),
    );

    expect($history->status)->toBe(MergeHistoryStatus::Active)
        ->and($history->non_surviving_prior_status)->toBe(PatientStatus::Inactive)
        ->and((int) $history->version)->toBe(0);

    $actorId = (string) Str::uuid7();
    $history->reverse($actorId);

    expect($history->status)->toBe(MergeHistoryStatus::Reversed)
        ->and($history->unmerged_by_user_id)->toBe($actorId)
        ->and($history->unmerged_at)->not->toBeNull();

    expect(fn () => $history->reverse((string) Str::uuid7()))->toThrow(PatientMergeRejected::class);
});
