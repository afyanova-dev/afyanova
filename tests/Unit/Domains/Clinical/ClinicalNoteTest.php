<?php

declare(strict_types=1);

use App\Domains\Clinical\Contracts\Event\ClinicalNoteAmended;
use App\Domains\Clinical\Contracts\Event\ClinicalNoteCreated;
use App\Domains\Clinical\Contracts\Event\ClinicalNoteSigned;
use App\Domains\Clinical\Domain\Enum\ClinicalNoteAmendmentType;
use App\Domains\Clinical\Domain\Enum\ClinicalNoteStatus;
use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Domains\Clinical\Domain\Model\ClinicalNoteAmendment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.1) unit gate — the ClinicalNote aggregate (12-clinical-domain.md
 | §10–§15; ADR-132, ADR-134). In-memory behaviour only — no DB.
 */

function makeDraftNote(): ClinicalNote
{
    return ClinicalNote::create(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        noteType: 'progress_note',
        title: 'Progress note',
        body: 'Initial assessment.',
        occurredAt: CarbonImmutable::now(),
        recordedAt: CarbonImmutable::now(),
    );
}

// ── creation ───────────────────────────────────────────────────────

test('create() produces a DRAFT at version 0 with the context + time set and one ClinicalNoteCreated', function () {
    $note = makeDraftNote();

    expect($note->status)->toBe(ClinicalNoteStatus::Draft)
        ->and((int) $note->version)->toBe(0)
        ->and($note->authored_context)->toBe(ClinicalNote::AUTHORED_CONTEXT_CLINICIAN)
        ->and($note->amendment_count)->toBe(0)
        ->and($note->signed_at)->toBeNull()
        ->and($note->signed_by_user_id)->toBeNull()
        ->and($note->occurred_at)->not->toBeNull()
        ->and($note->recorded_at)->not->toBeNull()
        ->and($note->isAmended())->toBeFalse();

    $events = $note->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ClinicalNoteCreated::class)
        ->and($events[0]->status)->toBe('DRAFT')
        ->and($events[0]->noteType)->toBe('progress_note')
        ->and($events[0]->clinicalNoteId)->toBe((string) $note->id);
});

test('create() defaults authored_context to CLINICIAN (ADR-134; the C.1 behaviour)', function () {
    expect(makeDraftNote()->authored_context)->toBe(ClinicalNote::AUTHORED_CONTEXT_CLINICIAN);
});

test('create() accepts authoredContext = NURSING and stores it (ADR-144 — a nursing note is a ClinicalNote)', function () {
    $note = ClinicalNote::create(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        noteType: 'nursing_note',
        title: 'Nursing admission note',
        body: 'Patient settled on the ward. Obs stable.',
        occurredAt: CarbonImmutable::now(),
        recordedAt: CarbonImmutable::now(),
        authoredContext: ClinicalNote::AUTHORED_CONTEXT_NURSING,
    );

    expect($note->authored_context)->toBe('NURSING')
        ->and($note->status)->toBe(ClinicalNoteStatus::Draft)
        ->and((int) $note->version)->toBe(0);

    // the emitted ClinicalNoteCreated is unchanged — no authored_context on the event
    $events = $note->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ClinicalNoteCreated::class)
        ->and(property_exists($events[0], 'authoredContext'))->toBeFalse();
});

test('create() rejects an out-of-set authoredContext (ADR-144 set-membership guard)', function () {
    foreach (['PROCEDURE', 'nursing', ''] as $bad) {
        expect(fn () => ClinicalNote::create(
            id: (string) Str::uuid7(),
            tenantId: (string) Str::uuid7(),
            facilityId: (string) Str::uuid7(),
            patientId: (string) Str::uuid7(),
            encounterId: (string) Str::uuid7(),
            authorUserId: (string) Str::uuid7(),
            noteType: 'progress_note',
            title: null,
            body: 'x',
            occurredAt: CarbonImmutable::now(),
            recordedAt: CarbonImmutable::now(),
            authoredContext: $bad,
        ))->toThrow(InvalidArgumentException::class, 'authored_context');
    }
});

test('AUTHORED_CONTEXTS is exactly {CLINICIAN, NURSING} (ADR-144)', function () {
    expect(ClinicalNote::AUTHORED_CONTEXTS)->toBe(['CLINICIAN', 'NURSING']);
});

// ── signing ────────────────────────────────────────────────────────

test('sign() moves DRAFT -> SIGNED, sets signed_at / signed_by once, records ClinicalNoteSigned', function () {
    $note = makeDraftNote();
    $note->releaseEvents();
    $signer = (string) Str::uuid7();

    $note->sign($signer);

    expect($note->status)->toBe(ClinicalNoteStatus::Signed)
        ->and($note->signed_at)->not->toBeNull()
        ->and($note->signed_by_user_id)->toBe($signer);

    $events = $note->releaseEvents();
    expect($events)->toHaveCount(1)->and($events[0])->toBeInstanceOf(ClinicalNoteSigned::class)
        ->and($events[0]->clinicalNoteId)->toBe((string) $note->id);
});

// ── cancellation ───────────────────────────────────────────────────

test('cancel() moves DRAFT -> CANCELLED, changes only status, records NO event', function () {
    $note = makeDraftNote();
    $note->releaseEvents();
    $bodyBefore = $note->body;

    $note->cancel();

    expect($note->status)->toBe(ClinicalNoteStatus::Cancelled)
        ->and($note->body)->toBe($bodyBefore)
        ->and($note->signed_at)->toBeNull()
        ->and($note->releaseEvents())->toBe([]);
});

// ── amendment ──────────────────────────────────────────────────────

test('amend() appends one ClinicalNoteAmendment, bumps amendment_count, leaves body untouched, records ClinicalNoteAmended', function () {
    $note = makeDraftNote();
    $note->sign((string) Str::uuid7());
    $note->releaseEvents();
    $bodyBefore = $note->body;

    $note->amend(
        type: ClinicalNoteAmendmentType::Correction,
        body: 'BP was 130/80, not 120/70.',
        reason: 'transcription error',
        authorUserId: (string) Str::uuid7(),
        occurredAt: CarbonImmutable::now(),
        recordedAt: CarbonImmutable::now(),
    );

    expect($note->body)->toBe($bodyBefore)                 // signed content untouched
        ->and($note->status)->toBe(ClinicalNoteStatus::Signed)
        ->and($note->amendment_count)->toBe(1)
        ->and($note->isAmended())->toBeTrue();

    $pending = $note->releasePendingAmendments();
    expect($pending)->toHaveCount(1)
        ->and($pending[0])->toBeInstanceOf(ClinicalNoteAmendment::class)
        ->and($pending[0]->clinical_note_id)->toBe((string) $note->id)
        ->and($pending[0]->reason)->toBe('transcription error')
        ->and($pending[0]->amendment_type)->toBe(ClinicalNoteAmendmentType::Correction)
        ->and($note->releasePendingAmendments())->toBe([]); // drained

    $events = $note->releaseEvents();
    expect($events)->toHaveCount(1)->and($events[0])->toBeInstanceOf(ClinicalNoteAmended::class)
        ->and($events[0]->amendmentType)->toBe('CORRECTION');
});

test('a second amend() increments amendment_count to 2', function () {
    $note = makeDraftNote();
    $note->sign((string) Str::uuid7());

    foreach ([ClinicalNoteAmendmentType::Addendum, ClinicalNoteAmendmentType::Amendment] as $type) {
        $note->amend($type, 'x', 'r', (string) Str::uuid7(), CarbonImmutable::now(), CarbonImmutable::now());
    }

    expect($note->amendment_count)->toBe(2)
        ->and($note->releasePendingAmendments())->toHaveCount(2);
});

// ── derived AMENDED ────────────────────────────────────────────────

test('isAmended() is true only for SIGNED with >= 1 amendment', function () {
    $draft = makeDraftNote();
    expect($draft->isAmended())->toBeFalse();

    $signed = makeDraftNote();
    $signed->sign((string) Str::uuid7());
    expect($signed->isAmended())->toBeFalse();

    $signed->amend(ClinicalNoteAmendmentType::Addendum, 'x', 'r', (string) Str::uuid7(), CarbonImmutable::now(), CarbonImmutable::now());
    expect($signed->isAmended())->toBeTrue();
});

test('the aggregate is final and there is no persisted AMENDED status case', function () {
    expect((new ReflectionClass(ClinicalNote::class))->isFinal())->toBeTrue()
        ->and(array_map(fn ($c) => $c->value, ClinicalNoteStatus::cases()))
        ->toEqualCanonicalizing(['DRAFT', 'SIGNED', 'CANCELLED']);
});
