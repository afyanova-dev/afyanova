<?php

declare(strict_types=1);

use App\Domains\Clinical\Contracts\Event\ProblemAdded;
use App\Domains\Clinical\Contracts\Event\ProblemResolved;
use App\Domains\Clinical\Contracts\Event\ProblemUpdated;
use App\Domains\Clinical\Domain\Exception\IllegalProblemStatusTransition;
use App\Domains\Clinical\Domain\Model\Problem;
use App\Domains\Clinical\Domain\Model\ProblemHistoryEntry;
use App\Domains\Clinical\Domain\ValueObject\ProblemChangeType;
use App\Domains\Clinical\Domain\ValueObject\ProblemCode;
use App\Domains\Clinical\Domain\ValueObject\ProblemStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.4) unit gate — the Problem aggregate (12-clinical-domain.md
 | §25–§27, §74; ADR-132, ADR-140). In-memory only: stable identity + bounded
 | mutation + append-only pre-change history; ACTIVE → RESOLVED only.
 */

function makeProblem(?ProblemCode $coding = null, ?CarbonImmutable $onsetDate = null, ?string $sourceDiagnosisId = null): Problem
{
    return Problem::add(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        coding: $coding ?? ProblemCode::of(code: 'I10', codeSystem: 'ICD-10'),
        description: 'Essential (primary) hypertension',
        onsetDate: $onsetDate,
        sourceDiagnosisId: $sourceDiagnosisId,
        occurredAt: CarbonImmutable::now(),
        recordedAt: CarbonImmutable::now(),
    );
}

// ── adding ─────────────────────────────────────────────────────────

test('add() produces a version-0 ACTIVE problem with CLINICIAN context + coding, one ProblemAdded', function () {
    $onset = CarbonImmutable::parse('2019-03-01');
    $p = makeProblem(onsetDate: $onset, sourceDiagnosisId: $src = (string) Str::uuid7());

    expect((int) $p->version)->toBe(0)
        ->and($p->status)->toBe(ProblemStatus::ACTIVE)
        ->and($p->authored_context)->toBe('CLINICIAN')
        ->and($p->code)->toBe('I10')
        ->and($p->code_system)->toBe('ICD-10')
        ->and($p->description)->toBe('Essential (primary) hypertension')
        ->and($p->onset_date->toDateString())->toBe('2019-03-01')
        ->and($p->resolved_at)->toBeNull()
        ->and($p->source_diagnosis_id)->toBe($src)
        ->and($p->isActive())->toBeTrue()
        ->and($p->isResolved())->toBeFalse()
        ->and($p->releasePendingHistory())->toBe([]);

    $events = $p->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ProblemAdded::class)
        ->and($events[0]->problemId)->toBe((string) $p->id)
        ->and($events[0]->problemCodeSystem)->toBe('ICD-10');
});

test('an un-coded problem records a null problemCodeSystem on the event', function () {
    $p = makeProblem(ProblemCode::none());
    expect($p->code)->toBeNull()->and($p->code_system)->toBeNull();
    expect($p->releaseEvents()[0]->problemCodeSystem)->toBeNull();
});

test('add() rejects a blank description', function () {
    expect(fn () => Problem::add(
        id: (string) Str::uuid7(), tenantId: (string) Str::uuid7(), facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(), encounterId: (string) Str::uuid7(), authorUserId: (string) Str::uuid7(),
        coding: ProblemCode::none(), description: '   ', onsetDate: null, sourceDiagnosisId: null,
        occurredAt: CarbonImmutable::now(), recordedAt: CarbonImmutable::now(),
    ))->toThrow(InvalidArgumentException::class);
});

// ── revising (REVISION / CORRECTION) ──────────────────────────────

test('revise() captures the COMPLETE pre-change state as one history row, then applies the new values, records ProblemUpdated', function () {
    $p = makeProblem(onsetDate: CarbonImmutable::parse('2019-03-01'));
    $p->releaseEvents();

    $p->revise(
        changeType: ProblemChangeType::CORRECTION,
        coding: ProblemCode::of(code: 'I11.9', codeSystem: 'ICD-10'),
        description: 'Hypertensive heart disease without heart failure',
        onsetDate: CarbonImmutable::parse('2018-11-15'),
        reason: 'reclassified after echo',
        authorUserId: $author = (string) Str::uuid7(),
        changeOccurredAt: CarbonImmutable::now(),
        changeRecordedAt: CarbonImmutable::now(),
    );

    // new state applied
    expect($p->code)->toBe('I11.9')
        ->and($p->description)->toBe('Hypertensive heart disease without heart failure')
        ->and($p->onset_date->toDateString())->toBe('2018-11-15')
        ->and($p->status)->toBe(ProblemStatus::ACTIVE); // unchanged

    // one pre-change history row with the OLD values
    $history = $p->releasePendingHistory();
    expect($history)->toHaveCount(1);
    $h = $history[0];
    expect($h)->toBeInstanceOf(ProblemHistoryEntry::class)
        ->and($h->change_type)->toBe(ProblemChangeType::CORRECTION)
        ->and($h->code)->toBe('I10')
        ->and($h->description)->toBe('Essential (primary) hypertension')
        ->and($h->onset_date->toDateString())->toBe('2019-03-01')
        ->and($h->status)->toBe(ProblemStatus::ACTIVE)
        ->and($h->resolved_at)->toBeNull()
        ->and($h->reason)->toBe('reclassified after echo')
        ->and($h->author_user_id)->toBe($author)
        ->and($h->problem_id)->toBe((string) $p->id)
        ->and((int) $h->version)->toBe(0);

    $events = $p->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ProblemUpdated::class)
        ->and($events[0]->changeType)->toBe('CORRECTION')
        ->and($events[0]->problemId)->toBe((string) $p->id);
});

test('revise() as REVISION carries a null reason on the history row', function () {
    $p = makeProblem();
    $p->releaseEvents();

    $p->revise(
        changeType: ProblemChangeType::REVISION,
        coding: ProblemCode::none(),
        description: 'Hypertension, now controlled on medication',
        onsetDate: null,
        reason: null,
        authorUserId: (string) Str::uuid7(),
        changeOccurredAt: CarbonImmutable::now(),
        changeRecordedAt: CarbonImmutable::now(),
    );

    $h = $p->releasePendingHistory()[0];
    expect($h->change_type)->toBe(ProblemChangeType::REVISION)->and($h->reason)->toBeNull();
    expect($p->code)->toBeNull()->and($p->onset_date)->toBeNull();
});

test('revise() is status-agnostic — it may correct a RESOLVED problem and never touches status / resolved_at (Decision 1)', function () {
    $p = makeProblem();
    $p->releaseEvents();
    $p->resolve(CarbonImmutable::parse('2024-01-10T09:00:00Z'));
    $p->releaseEvents();
    $resolvedAt = $p->resolved_at;

    $p->revise(
        changeType: ProblemChangeType::CORRECTION,
        coding: ProblemCode::of(code: 'I10', codeSystem: 'ICD-10'),
        description: 'corrected wording on a resolved problem',
        onsetDate: null,
        reason: 'typo fix',
        authorUserId: (string) Str::uuid7(),
        changeOccurredAt: CarbonImmutable::now(),
        changeRecordedAt: CarbonImmutable::now(),
    );

    expect($p->status)->toBe(ProblemStatus::RESOLVED)
        ->and($p->resolved_at->equalTo($resolvedAt))->toBeTrue()
        ->and($p->description)->toBe('corrected wording on a resolved problem');

    $h = $p->releasePendingHistory()[0];
    expect($h->status)->toBe(ProblemStatus::RESOLVED)     // the pre-change snapshot records it was RESOLVED
        ->and($h->resolved_at->equalTo($resolvedAt))->toBeTrue();
});

test('revise() rejects a blank description', function () {
    $p = makeProblem();
    expect(fn () => $p->revise(
        changeType: ProblemChangeType::REVISION, coding: ProblemCode::none(), description: '  ',
        onsetDate: null, reason: null, authorUserId: (string) Str::uuid7(),
        changeOccurredAt: CarbonImmutable::now(), changeRecordedAt: CarbonImmutable::now(),
    ))->toThrow(InvalidArgumentException::class);
});

// ── resolving (ACTIVE → RESOLVED only) ────────────────────────────

test('resolve() moves ACTIVE → RESOLVED, sets resolved_at once, records ProblemResolved, writes NO history row', function () {
    $p = makeProblem();
    $p->releaseEvents();

    $p->resolve($at = CarbonImmutable::parse('2024-02-01T12:00:00Z'));

    expect($p->status)->toBe(ProblemStatus::RESOLVED)
        ->and($p->resolved_at->equalTo($at))->toBeTrue()
        ->and($p->isResolved())->toBeTrue()
        ->and($p->releasePendingHistory())->toBe([]); // ADR-140 D-P — resolution writes no history row

    $events = $p->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ProblemResolved::class)
        ->and($events[0]->problemId)->toBe((string) $p->id)
        ->and($events[0]->resolvedAt)->toBe($at->toISOString());
});

test('resolve() on an already-RESOLVED problem -> IllegalProblemStatusTransition (409), nothing changes', function () {
    $p = makeProblem();
    $p->resolve(CarbonImmutable::now());
    $p->releaseEvents();

    try {
        $p->resolve(CarbonImmutable::now());
        test()->fail('expected IllegalProblemStatusTransition');
    } catch (IllegalProblemStatusTransition $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('illegal_problem_status_transition');
    }

    expect($p->releaseEvents())->toBe([]);
});

test('there is no reactivation path and the aggregate is final', function () {
    $p = makeProblem();
    expect(method_exists($p, 'reactivate'))->toBeFalse();
    expect((new ReflectionClass(Problem::class))->isFinal())->toBeTrue();
});

test('immutable origin: encounter_id / facility_id / patient_id are set at add() and never changed by revise / resolve', function () {
    $p = makeProblem();
    $encounter = (string) $p->encounter_id;
    $facility = (string) $p->facility_id;
    $patient = (string) $p->patient_id;

    $p->revise(
        changeType: ProblemChangeType::REVISION, coding: ProblemCode::none(), description: 'x',
        onsetDate: null, reason: null, authorUserId: (string) Str::uuid7(),
        changeOccurredAt: CarbonImmutable::now(), changeRecordedAt: CarbonImmutable::now(),
    );
    $p->resolve(CarbonImmutable::now());

    expect((string) $p->encounter_id)->toBe($encounter)
        ->and((string) $p->facility_id)->toBe($facility)
        ->and((string) $p->patient_id)->toBe($patient);
});
