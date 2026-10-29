<?php

declare(strict_types=1);

use App\Domains\Clinical\Contracts\Event\VitalSignsObservationCorrected;
use App\Domains\Clinical\Contracts\Event\VitalSignsRecorded;
use App\Domains\Clinical\Domain\Exception\VitalSignsObservationAlreadySuperseded;
use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Domains\Clinical\Domain\ValueObject\VitalSigns;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.2) unit gate — the VitalSignsObservation aggregate
 | (12-clinical-domain.md §17–§22, §94; ADR-132, ADR-138). In-memory only.
 */

function makeObservation(?VitalSigns $measurements = null): VitalSignsObservation
{
    return VitalSignsObservation::record(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        authoredContext: VitalSignsObservation::AUTHORED_CONTEXT_NURSING,
        measurements: $measurements ?? VitalSigns::of(temperature: 37.1, heartRate: 74, systolicBp: 120, diastolicBp: 78),
        occurredAt: CarbonImmutable::now(),
        recordedAt: CarbonImmutable::now(),
    );
}

// ── recording ──────────────────────────────────────────────────────

test('record() produces a version-0 observation with context + measurements + times, one VitalSignsRecorded, no supersession', function () {
    $o = makeObservation();

    expect((int) $o->version)->toBe(0)
        ->and($o->authored_context)->toBe('NURSING')
        ->and((float) $o->temperature)->toBe(37.1)
        ->and($o->heart_rate)->toBe(74)
        ->and($o->systolic_bp)->toBe(120)
        ->and($o->pain_score)->toBeNull()
        ->and($o->occurred_at)->not->toBeNull()
        ->and($o->recorded_at)->not->toBeNull()
        ->and($o->supersedes_observation_id)->toBeNull()
        ->and($o->superseded_at)->toBeNull()
        ->and($o->reason)->toBeNull()
        ->and($o->isSuperseded())->toBeFalse()
        ->and($o->isCorrection())->toBeFalse();

    $events = $o->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(VitalSignsRecorded::class)
        ->and($events[0]->vitalSignsObservationId)->toBe((string) $o->id);
});

test('record() rejects an unknown authored_context (ADR-138 D8)', function () {
    expect(fn () => VitalSignsObservation::record(
        id: (string) Str::uuid7(), tenantId: (string) Str::uuid7(), facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(), encounterId: (string) Str::uuid7(), authorUserId: (string) Str::uuid7(),
        authoredContext: 'DEVICE',
        measurements: VitalSigns::of(heartRate: 70),
        occurredAt: CarbonImmutable::now(), recordedAt: CarbonImmutable::now(),
    ))->toThrow(InvalidArgumentException::class);
});

// ── correction (superseding observation) ────────────────────────────

test('correcting() builds a new observation that copies the original context, carries supersedes id + reason, and records VitalSignsObservationCorrected', function () {
    $original = makeObservation();
    $original->releaseEvents();

    $correction = VitalSignsObservation::correcting(
        id: (string) Str::uuid7(),
        original: $original,
        authorUserId: (string) Str::uuid7(),
        authoredContext: VitalSignsObservation::AUTHORED_CONTEXT_CLINICIAN,
        measurements: VitalSigns::of(temperature: 37.1, heartRate: 74, systolicBp: 128, diastolicBp: 82),
        reason: 'BP mis-transcribed, actual 128/82',
        occurredAt: $original->occurred_at,
        recordedAt: CarbonImmutable::now(),
    );

    expect($correction->tenant_id)->toBe($original->tenant_id)
        ->and($correction->facility_id)->toBe($original->facility_id)
        ->and($correction->patient_id)->toBe($original->patient_id)
        ->and($correction->encounter_id)->toBe($original->encounter_id)
        ->and($correction->supersedes_observation_id)->toBe((string) $original->id)
        ->and($correction->reason)->toBe('BP mis-transcribed, actual 128/82')
        ->and($correction->systolic_bp)->toBe(128)
        ->and($correction->isCorrection())->toBeTrue();

    $events = $correction->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(VitalSignsObservationCorrected::class)
        ->and($events[0]->vitalSignsObservationId)->toBe((string) $correction->id)
        ->and($events[0]->supersedesObservationId)->toBe((string) $original->id);
});

test('correcting() requires a non-blank reason', function () {
    $original = makeObservation();
    expect(fn () => VitalSignsObservation::correcting(
        id: (string) Str::uuid7(), original: $original, authorUserId: (string) Str::uuid7(),
        authoredContext: VitalSignsObservation::AUTHORED_CONTEXT_CLINICIAN,
        measurements: VitalSigns::of(heartRate: 80), reason: '   ',
        occurredAt: $original->occurred_at, recordedAt: CarbonImmutable::now(),
    ))->toThrow(InvalidArgumentException::class);
});

// ── the one-time supersession stamp ────────────────────────────────

test('markSupersededBy() sets superseded_at + superseded_by once, changes nothing else, records NO event', function () {
    $o = makeObservation();
    $o->releaseEvents();
    $tempBefore = $o->temperature;
    $newId = (string) Str::uuid7();

    $o->markSupersededBy($newId);

    expect($o->superseded_at)->not->toBeNull()
        ->and($o->superseded_by_observation_id)->toBe($newId)
        ->and($o->temperature)->toBe($tempBefore)   // measurement values untouched
        ->and($o->isSuperseded())->toBeTrue()
        ->and($o->releaseEvents())->toBe([]);
});

test('markSupersededBy() a second time -> VitalSignsObservationAlreadySuperseded (409)', function () {
    $o = makeObservation();
    $o->markSupersededBy((string) Str::uuid7());

    try {
        $o->markSupersededBy((string) Str::uuid7());
        test()->fail('expected VitalSignsObservationAlreadySuperseded');
    } catch (VitalSignsObservationAlreadySuperseded $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('vital_signs_observation_already_superseded');
    }
});

test('there is no status column and the aggregate is final', function () {
    $o = makeObservation();
    expect(array_key_exists('status', $o->getAttributes()))->toBeFalse()
        ->and((new ReflectionClass(VitalSignsObservation::class))->isFinal())->toBeTrue();
});
