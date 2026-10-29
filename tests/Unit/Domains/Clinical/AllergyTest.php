<?php

declare(strict_types=1);

use App\Domains\Clinical\Contracts\Event\AllergyRecorded;
use App\Domains\Clinical\Contracts\Event\AllergyResolved;
use App\Domains\Clinical\Contracts\Event\AllergyUpdated;
use App\Domains\Clinical\Contracts\Event\AllergyVerified;
use App\Domains\Clinical\Domain\Exception\IllegalAllergyStatusTransition;
use App\Domains\Clinical\Domain\Model\Allergy;
use App\Domains\Clinical\Domain\Model\AllergyHistoryEntry;
use App\Domains\Clinical\Domain\ValueObject\AllergyChangeType;
use App\Domains\Clinical\Domain\ValueObject\AllergyStatus;
use App\Domains\Clinical\Domain\ValueObject\AllergySubstance;
use App\Domains\Clinical\Domain\ValueObject\AllergyVerificationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.5) unit gate — the Allergy aggregate root (12-clinical-domain.md
 | §30–§33, §75; ADR-141 D-A … D-V). Pure allergy, encounter origination,
 | two-axis status model with REFUTED <=> INACTIVE, bounded mutations via four
 | commands, append-only history.
 */

test('record() produces a version-0 ACTIVE allergy with SUSPECTED verification by default', function () {
    $id = (string) Str::uuid7();
    $substance = AllergySubstance::of(name: 'Penicillin', code: '764146007', codeSystem: 'SNOMED-CT');
    $now = CarbonImmutable::now();

    $allergy = Allergy::record(
        id: $id,
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        authoredContext: Allergy::AUTHORED_CONTEXT_CLINICIAN,
        category: 'DRUG',
        substance: $substance,
        reactionDescription: 'Hives and facial swelling',
        severity: 'MODERATE',
        verificationStatus: AllergyVerificationStatus::SUSPECTED,
        onsetDate: CarbonImmutable::parse('2025-01-15'),
        occurredAt: $now,
        recordedAt: $now,
    );

    expect((string) $allergy->id)->toBe($id)
        ->and((string) $allergy->clinical_status)->toBe(AllergyStatus::ACTIVE)
        ->and((string) $allergy->verification_status)->toBe(AllergyVerificationStatus::SUSPECTED)
        ->and($allergy->isActive())->toBeTrue()
        ->and($allergy->isResolved())->toBeFalse()
        ->and($allergy->isInactive())->toBeFalse()
        ->and($allergy->category)->toBe('DRUG')
        ->and($allergy->substance_name)->toBe('Penicillin')
        ->and($allergy->substance_code)->toBe('764146007')
        ->and($allergy->substance_code_system)->toBe('SNOMED-CT')
        ->and($allergy->reaction_description)->toBe('Hives and facial swelling')
        ->and($allergy->severity)->toBe('MODERATE')
        ->and($allergy->authored_context)->toBe('CLINICIAN')
        ->and($allergy->resolved_at)->toBeNull();

    $events = $allergy->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(AllergyRecorded::class);

    /** @var AllergyRecorded $event */
    $event = $events[0];
    expect($event->allergyId)->toBe($id)
        ->and($event->category)->toBe('DRUG')
        ->and($event->clinicalStatus)->toBe('ACTIVE')
        ->and($event->verificationStatus)->toBe('SUSPECTED')
        ->and($event->substanceCodeSystem)->toBe('SNOMED-CT');
});

test('record() accepts NURSING authored_context and CONFIRMED verificationStatus', function () {
    $allergy = Allergy::record(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        authoredContext: Allergy::AUTHORED_CONTEXT_NURSING,
        category: 'FOOD',
        substance: AllergySubstance::of('Peanuts'),
        reactionDescription: null,
        severity: null,
        verificationStatus: AllergyVerificationStatus::CONFIRMED,
        onsetDate: null,
        occurredAt: CarbonImmutable::now(),
        recordedAt: CarbonImmutable::now(),
    );

    expect($allergy->authored_context)->toBe(Allergy::AUTHORED_CONTEXT_NURSING)
        ->and((string) $allergy->verification_status)->toBe(AllergyVerificationStatus::CONFIRMED)
        ->and($allergy->substance_code)->toBeNull()
        ->and($allergy->substance_code_system)->toBeNull();

    /** @var AllergyRecorded $event */
    $event = $allergy->releaseEvents()[0];
    expect($event->substanceCodeSystem)->toBeNull();
});

test('record() rejects invalid authored_context or blank category', function () {
    $validArgs = [
        'id' => (string) Str::uuid7(),
        'tenantId' => (string) Str::uuid7(),
        'facilityId' => (string) Str::uuid7(),
        'patientId' => (string) Str::uuid7(),
        'encounterId' => (string) Str::uuid7(),
        'authorUserId' => (string) Str::uuid7(),
        'authoredContext' => 'INVALID',
        'category' => 'DRUG',
        'substance' => AllergySubstance::of('Aspirin'),
        'reactionDescription' => null,
        'severity' => null,
        'verificationStatus' => AllergyVerificationStatus::SUSPECTED,
        'onsetDate' => null,
        'occurredAt' => CarbonImmutable::now(),
        'recordedAt' => CarbonImmutable::now(),
    ];

    expect(fn () => Allergy::record(...$validArgs))->toThrow(InvalidArgumentException::class);

    $validArgs['authoredContext'] = Allergy::AUTHORED_CONTEXT_CLINICIAN;
    $validArgs['category'] = '   ';
    expect(fn () => Allergy::record(...$validArgs))->toThrow(InvalidArgumentException::class);
});

test('revise() captures the COMPLETE pre-change state as one history row and updates content', function () {
    $now = CarbonImmutable::now();
    $allergy = Allergy::record(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        authoredContext: Allergy::AUTHORED_CONTEXT_CLINICIAN,
        category: 'DRUG',
        substance: AllergySubstance::of('Penicillin'),
        reactionDescription: 'Mild rash',
        severity: 'MILD',
        verificationStatus: AllergyVerificationStatus::SUSPECTED,
        onsetDate: null,
        occurredAt: $now,
        recordedAt: $now,
    );
    $allergy->releaseEvents();

    $reviserId = (string) Str::uuid7();
    $allergy->revise(
        changeType: AllergyChangeType::CORRECTION,
        substance: AllergySubstance::of('Amoxicillin', '372687004', 'SNOMED-CT'),
        category: 'DRUG',
        reactionDescription: 'Anaphylaxis',
        severity: 'SEVERE',
        onsetDate: CarbonImmutable::parse('2025-02-01'),
        reason: 'Misidentified as penicillin originally; amoxicillin confirmed',
        authorUserId: $reviserId,
        changeOccurredAt: $now,
        changeRecordedAt: $now,
    );

    // In-memory state updated
    expect($allergy->substance_name)->toBe('Amoxicillin')
        ->and($allergy->substance_code)->toBe('372687004')
        ->and($allergy->substance_code_system)->toBe('SNOMED-CT')
        ->and($allergy->reaction_description)->toBe('Anaphylaxis')
        ->and($allergy->severity)->toBe('SEVERE')
        ->and($allergy->clinical_status)->toBe(AllergyStatus::ACTIVE) // untouched
        ->and($allergy->verification_status)->toBe(AllergyVerificationStatus::SUSPECTED); // untouched

    // Pending history captured
    $history = $allergy->releasePendingHistory();
    expect($history)->toHaveCount(1);

    /** @var AllergyHistoryEntry $h */
    $h = $history[0];
    expect($h->change_kind)->toBe(AllergyHistoryEntry::CHANGE_KIND_CONTENT)
        ->and($h->change_type)->toBe(AllergyChangeType::CORRECTION)
        ->and($h->reason)->toBe('Misidentified as penicillin originally; amoxicillin confirmed')
        ->and($h->substance_name)->toBe('Penicillin') // PRE-CHANGE snapshot
        ->and($h->reaction_description)->toBe('Mild rash') // PRE-CHANGE snapshot
        ->and($h->severity)->toBe('MILD') // PRE-CHANGE snapshot
        ->and($h->author_user_id)->toBe($reviserId);

    // Event emitted
    $events = $allergy->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(AllergyUpdated::class);
    /** @var AllergyUpdated $event */
    $event = $events[0];
    expect($event->changeType)->toBe(AllergyChangeType::CORRECTION)
        ->and($event->substanceCodeSystem)->toBe('SNOMED-CT');
});

test('revise() as REVISION carries null reason on history row', function () {
    $now = CarbonImmutable::now();
    $allergy = Allergy::record(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        authoredContext: Allergy::AUTHORED_CONTEXT_CLINICIAN,
        category: 'FOOD',
        substance: AllergySubstance::of('Eggs'),
        reactionDescription: null,
        severity: null,
        verificationStatus: AllergyVerificationStatus::SUSPECTED,
        onsetDate: null,
        occurredAt: $now,
        recordedAt: $now,
    );

    $allergy->revise(
        changeType: AllergyChangeType::REVISION,
        substance: AllergySubstance::of('Egg whites'),
        category: 'FOOD',
        reactionDescription: 'Gastrointestinal upset',
        severity: 'MILD',
        onsetDate: null,
        reason: null,
        authorUserId: (string) Str::uuid7(),
        changeOccurredAt: $now,
        changeRecordedAt: $now,
    );

    $history = $allergy->releasePendingHistory();
    expect($history[0]->reason)->toBeNull()
        ->and($history[0]->change_type)->toBe(AllergyChangeType::REVISION);
});

test('revise() is status-agnostic — can revise a RESOLVED or INACTIVE allergy', function () {
    $now = CarbonImmutable::now();
    $allergy = Allergy::record(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        authoredContext: Allergy::AUTHORED_CONTEXT_CLINICIAN,
        category: 'DRUG',
        substance: AllergySubstance::of('Penicillin'),
        reactionDescription: null,
        severity: null,
        verificationStatus: AllergyVerificationStatus::SUSPECTED,
        onsetDate: null,
        occurredAt: $now,
        recordedAt: $now,
    );
    $allergy->resolve($now);

    expect($allergy->isResolved())->toBeTrue();

    $allergy->revise(
        changeType: AllergyChangeType::REVISION,
        substance: AllergySubstance::of('Penicillin G'),
        category: 'DRUG',
        reactionDescription: 'Hives',
        severity: 'MILD',
        onsetDate: null,
        reason: null,
        authorUserId: (string) Str::uuid7(),
        changeOccurredAt: $now,
        changeRecordedAt: $now,
    );

    expect($allergy->isResolved())->toBeTrue() // remains RESOLVED
        ->and($allergy->substance_name)->toBe('Penicillin G');
});

test('verify(CONFIRMED) transitions SUSPECTED -> CONFIRMED with clinical_status unchanged', function () {
    $now = CarbonImmutable::now();
    $allergy = Allergy::record(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        authoredContext: Allergy::AUTHORED_CONTEXT_CLINICIAN,
        category: 'DRUG',
        substance: AllergySubstance::of('Penicillin'),
        reactionDescription: null,
        severity: null,
        verificationStatus: AllergyVerificationStatus::SUSPECTED,
        onsetDate: null,
        occurredAt: $now,
        recordedAt: $now,
    );
    $allergy->releaseEvents();

    $allergy->verify(
        verificationStatus: AllergyVerificationStatus::CONFIRMED,
        reason: null,
        authorUserId: (string) Str::uuid7(),
        changeOccurredAt: $now,
        changeRecordedAt: $now,
    );

    expect((string) $allergy->verification_status)->toBe(AllergyVerificationStatus::CONFIRMED)
        ->and((string) $allergy->clinical_status)->toBe(AllergyStatus::ACTIVE);

    $history = $allergy->releasePendingHistory();
    expect($history)->toHaveCount(1)
        ->and($history[0]->change_kind)->toBe(AllergyHistoryEntry::CHANGE_KIND_VERIFICATION)
        ->and($history[0]->change_type)->toBe(AllergyVerificationStatus::CONFIRMED)
        ->and($history[0]->verification_status)->toBe(AllergyVerificationStatus::SUSPECTED); // pre-change

    $events = $allergy->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(AllergyVerified::class);
    /** @var AllergyVerified $event */
    $event = $events[0];
    expect($event->verificationStatus)->toBe(AllergyVerificationStatus::CONFIRMED)
        ->and($event->clinicalStatus)->toBe(AllergyStatus::ACTIVE);
});

test('verify(CONFIRMED) on already-CONFIRMED throws IllegalAllergyStatusTransition', function () {
    $now = CarbonImmutable::now();
    $allergy = Allergy::record(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        authoredContext: Allergy::AUTHORED_CONTEXT_CLINICIAN,
        category: 'DRUG',
        substance: AllergySubstance::of('Penicillin'),
        reactionDescription: null,
        severity: null,
        verificationStatus: AllergyVerificationStatus::CONFIRMED,
        onsetDate: null,
        occurredAt: $now,
        recordedAt: $now,
    );

    expect(fn () => $allergy->verify(
        verificationStatus: AllergyVerificationStatus::CONFIRMED,
        reason: null,
        authorUserId: (string) Str::uuid7(),
        changeOccurredAt: $now,
        changeRecordedAt: $now,
    ))->toThrow(IllegalAllergyStatusTransition::class);
});

test('verify(REFUTED) atomically drives clinical_status -> INACTIVE and requires a reason', function () {
    $now = CarbonImmutable::now();
    $allergy = Allergy::record(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        authoredContext: Allergy::AUTHORED_CONTEXT_CLINICIAN,
        category: 'DRUG',
        substance: AllergySubstance::of('Penicillin'),
        reactionDescription: null,
        severity: null,
        verificationStatus: AllergyVerificationStatus::SUSPECTED,
        onsetDate: null,
        occurredAt: $now,
        recordedAt: $now,
    );
    $allergy->releaseEvents();

    $allergy->verify(
        verificationStatus: AllergyVerificationStatus::REFUTED,
        reason: 'Skin prick test negative; oral challenge tolerated with zero reaction',
        authorUserId: (string) Str::uuid7(),
        changeOccurredAt: $now,
        changeRecordedAt: $now,
    );

    // Biconditional REFUTED <=> INACTIVE
    expect((string) $allergy->verification_status)->toBe(AllergyVerificationStatus::REFUTED)
        ->and((string) $allergy->clinical_status)->toBe(AllergyStatus::INACTIVE)
        ->and($allergy->isInactive())->toBeTrue();

    $history = $allergy->releasePendingHistory();
    expect($history)->toHaveCount(1)
        ->and($history[0]->change_kind)->toBe(AllergyHistoryEntry::CHANGE_KIND_VERIFICATION)
        ->and($history[0]->change_type)->toBe(AllergyVerificationStatus::REFUTED)
        ->and($history[0]->reason)->toBe('Skin prick test negative; oral challenge tolerated with zero reaction');

    $events = $allergy->releaseEvents();
    expect($events)->toHaveCount(1)
        /** @var AllergyVerified $event */
        ->and($events[0]->verificationStatus)->toBe(AllergyVerificationStatus::REFUTED)
        ->and($events[0]->clinicalStatus)->toBe(AllergyStatus::INACTIVE);
});

test('verify(REFUTED) on already-REFUTED throws IllegalAllergyStatusTransition (terminal)', function () {
    $now = CarbonImmutable::now();
    $allergy = Allergy::record(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        authoredContext: Allergy::AUTHORED_CONTEXT_CLINICIAN,
        category: 'DRUG',
        substance: AllergySubstance::of('Penicillin'),
        reactionDescription: null,
        severity: null,
        verificationStatus: AllergyVerificationStatus::SUSPECTED,
        onsetDate: null,
        occurredAt: $now,
        recordedAt: $now,
    );

    $allergy->verify(
        verificationStatus: AllergyVerificationStatus::REFUTED,
        reason: 'Negative test',
        authorUserId: (string) Str::uuid7(),
        changeOccurredAt: $now,
        changeRecordedAt: $now,
    );

    expect(fn () => $allergy->verify(
        verificationStatus: AllergyVerificationStatus::REFUTED,
        reason: 'Another negative',
        authorUserId: (string) Str::uuid7(),
        changeOccurredAt: $now,
        changeRecordedAt: $now,
    ))->toThrow(IllegalAllergyStatusTransition::class);
});

test('resolve() transitions ACTIVE -> RESOLVED, sets resolved_at once, records AllergyResolved', function () {
    $now = CarbonImmutable::now();
    $allergy = Allergy::record(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        authoredContext: Allergy::AUTHORED_CONTEXT_CLINICIAN,
        category: 'FOOD',
        substance: AllergySubstance::of('Milk'),
        reactionDescription: null,
        severity: null,
        verificationStatus: AllergyVerificationStatus::CONFIRMED,
        onsetDate: null,
        occurredAt: $now,
        recordedAt: $now,
    );
    $allergy->releaseEvents();

    $resolvedTime = CarbonImmutable::parse('2026-03-01 10:00:00');
    $allergy->resolve($resolvedTime);

    expect((string) $allergy->clinical_status)->toBe(AllergyStatus::RESOLVED)
        ->and((string) $allergy->verification_status)->toBe(AllergyVerificationStatus::CONFIRMED)
        ->and($allergy->isResolved())->toBeTrue()
        ->and($allergy->resolved_at?->toIso8601String())->toBe($resolvedTime->toIso8601String())
        ->and($allergy->releasePendingHistory())->toBeEmpty(); // writes NO history row

    $events = $allergy->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(AllergyResolved::class);
    /** @var AllergyResolved $event */
    $event = $events[0];
    expect($event->resolvedAt)->toBe($resolvedTime->toISOString());
});

test('resolve() on non-ACTIVE allergy throws IllegalAllergyStatusTransition', function () {
    $now = CarbonImmutable::now();
    $allergy = Allergy::record(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        authoredContext: Allergy::AUTHORED_CONTEXT_CLINICIAN,
        category: 'FOOD',
        substance: AllergySubstance::of('Milk'),
        reactionDescription: null,
        severity: null,
        verificationStatus: AllergyVerificationStatus::CONFIRMED,
        onsetDate: null,
        occurredAt: $now,
        recordedAt: $now,
    );

    $allergy->resolve($now);

    // Attempting to re-resolve throws
    expect(fn () => $allergy->resolve($now))->toThrow(IllegalAllergyStatusTransition::class);
});

test('aggregate is final', function () {
    $r = new ReflectionClass(Allergy::class);
    expect($r->isFinal())->toBeTrue();
});
