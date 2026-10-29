<?php

declare(strict_types=1);

use App\Domains\Clinical\Contracts\Event\DiagnosisCorrected;
use App\Domains\Clinical\Contracts\Event\DiagnosisRecorded;
use App\Domains\Clinical\Domain\Exception\DiagnosisAlreadySuperseded;
use App\Domains\Clinical\Domain\Model\Diagnosis;
use App\Domains\Clinical\Domain\ValueObject\DiagnosisCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.3) unit gate — the Diagnosis aggregate (12-clinical-domain.md
 | §23–§27, §73, §102; ADR-132, ADR-139). In-memory only.
 */

function makeDiagnosis(?DiagnosisCode $coding = null, string $status = 'WORKING'): Diagnosis
{
    return Diagnosis::record(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        coding: $coding ?? DiagnosisCode::of(code: 'B54', codeSystem: 'ICD-10'),
        description: 'Unspecified malaria',
        clinicalStatus: $status,
        occurredAt: CarbonImmutable::now(),
        recordedAt: CarbonImmutable::now(),
    );
}

// ── recording ──────────────────────────────────────────────────────

test('record() produces a version-0 diagnosis with CLINICIAN context + coding + status, one DiagnosisRecorded', function () {
    $d = makeDiagnosis();

    expect((int) $d->version)->toBe(0)
        ->and($d->authored_context)->toBe('CLINICIAN')
        ->and($d->code)->toBe('B54')
        ->and($d->code_system)->toBe('ICD-10')
        ->and($d->description)->toBe('Unspecified malaria')
        ->and($d->clinical_status)->toBe('WORKING')
        ->and($d->supersedes_diagnosis_id)->toBeNull()
        ->and($d->superseded_at)->toBeNull()
        ->and($d->reason)->toBeNull()
        ->and($d->isSuperseded())->toBeFalse()
        ->and($d->isCorrection())->toBeFalse();

    $events = $d->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(DiagnosisRecorded::class)
        ->and($events[0]->diagnosisId)->toBe((string) $d->id)
        ->and($events[0]->diagnosisCodeSystem)->toBe('ICD-10')
        ->and($events[0]->clinicalStatus)->toBe('WORKING');
});

test('an un-coded diagnosis records a null diagnosisCodeSystem on the event', function () {
    $d = makeDiagnosis(DiagnosisCode::none());
    expect($d->code)->toBeNull()->and($d->code_system)->toBeNull();

    $events = $d->releaseEvents();
    expect($events[0]->diagnosisCodeSystem)->toBeNull();
});

test('record() rejects a blank description / blank clinical_status', function () {
    expect(fn () => Diagnosis::record(
        id: (string) Str::uuid7(), tenantId: (string) Str::uuid7(), facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(), encounterId: (string) Str::uuid7(), authorUserId: (string) Str::uuid7(),
        coding: DiagnosisCode::none(), description: '   ', clinicalStatus: 'WORKING',
        occurredAt: CarbonImmutable::now(), recordedAt: CarbonImmutable::now(),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => Diagnosis::record(
        id: (string) Str::uuid7(), tenantId: (string) Str::uuid7(), facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(), encounterId: (string) Str::uuid7(), authorUserId: (string) Str::uuid7(),
        coding: DiagnosisCode::none(), description: 'x', clinicalStatus: '  ',
        occurredAt: CarbonImmutable::now(), recordedAt: CarbonImmutable::now(),
    ))->toThrow(InvalidArgumentException::class);
});

// ── correction (superseding diagnosis) ─────────────────────────────

test('correcting() builds a new diagnosis copying the original context, carries supersedes id + reason, records DiagnosisCorrected', function () {
    $original = makeDiagnosis();
    $original->releaseEvents();

    $correction = Diagnosis::correcting(
        id: (string) Str::uuid7(),
        original: $original,
        authorUserId: (string) Str::uuid7(),
        coding: DiagnosisCode::of(code: 'B50.9', codeSystem: 'ICD-10'),
        description: 'Plasmodium falciparum malaria without complication',
        clinicalStatus: 'CONFIRMED',
        reason: 'species confirmed on blood film',
        occurredAt: $original->occurred_at,
        recordedAt: CarbonImmutable::now(),
    );

    expect($correction->tenant_id)->toBe($original->tenant_id)
        ->and($correction->facility_id)->toBe($original->facility_id)
        ->and($correction->patient_id)->toBe($original->patient_id)
        ->and($correction->encounter_id)->toBe($original->encounter_id)
        ->and($correction->authored_context)->toBe('CLINICIAN')
        ->and($correction->supersedes_diagnosis_id)->toBe((string) $original->id)
        ->and($correction->code)->toBe('B50.9')
        ->and($correction->clinical_status)->toBe('CONFIRMED')
        ->and($correction->reason)->toBe('species confirmed on blood film')
        ->and($correction->isCorrection())->toBeTrue();

    $events = $correction->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(DiagnosisCorrected::class)
        ->and($events[0]->diagnosisId)->toBe((string) $correction->id)
        ->and($events[0]->supersedesDiagnosisId)->toBe((string) $original->id);
});

test('correcting() requires a non-blank reason', function () {
    $original = makeDiagnosis();
    expect(fn () => Diagnosis::correcting(
        id: (string) Str::uuid7(), original: $original, authorUserId: (string) Str::uuid7(),
        coding: DiagnosisCode::none(), description: 'x', clinicalStatus: 'WORKING', reason: '  ',
        occurredAt: $original->occurred_at, recordedAt: CarbonImmutable::now(),
    ))->toThrow(InvalidArgumentException::class);
});

// ── the one-time supersession stamp ───────────────────────────────

test('markSupersededBy() sets superseded_at + superseded_by once, changes nothing else, records NO event', function () {
    $d = makeDiagnosis();
    $d->releaseEvents();
    $codeBefore = $d->code;
    $descBefore = $d->description;
    $newId = (string) Str::uuid7();

    $d->markSupersededBy($newId);

    expect($d->superseded_at)->not->toBeNull()
        ->and($d->superseded_by_diagnosis_id)->toBe($newId)
        ->and($d->code)->toBe($codeBefore)         // clinical content untouched
        ->and($d->description)->toBe($descBefore)
        ->and($d->isSuperseded())->toBeTrue()
        ->and($d->releaseEvents())->toBe([]);
});

test('markSupersededBy() a second time -> DiagnosisAlreadySuperseded (409)', function () {
    $d = makeDiagnosis();
    $d->markSupersededBy((string) Str::uuid7());

    try {
        $d->markSupersededBy((string) Str::uuid7());
        test()->fail('expected DiagnosisAlreadySuperseded');
    } catch (DiagnosisAlreadySuperseded $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('diagnosis_already_superseded');
    }
});

test('there is no persisted lifecycle status beyond clinical_status; the aggregate is final', function () {
    $d = makeDiagnosis();
    expect(array_key_exists('status', $d->getAttributes()))->toBeFalse()
        ->and((new ReflectionClass(Diagnosis::class))->isFinal())->toBeTrue()
        ->and(Diagnosis::AUTHORED_CONTEXT)->toBe('CLINICIAN')
        ->and(Diagnosis::CLINICAL_STATUS_DEFAULT)->toBe('WORKING');
});
