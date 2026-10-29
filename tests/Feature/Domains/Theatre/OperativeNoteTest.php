<?php

declare(strict_types=1);

use App\Domains\Theatre\Application\Command\AmendOperativeNote;
use App\Domains\Theatre\Application\Command\CreateOperativeNote;
use App\Domains\Theatre\Application\Command\ReviewOperativeNote;
use App\Domains\Theatre\Application\Command\SignOperativeNote;
use App\Domains\Theatre\Application\Command\UpdateOperativeNoteContent;
use App\Domains\Theatre\Application\Query\GetOperativeNote;
use App\Domains\Theatre\Application\Query\GetOperativeNoteForCase;
use App\Domains\Theatre\Contracts\Dto\OperativeNoteSummary;
use App\Domains\Theatre\Contracts\Event\OperativeNoteAmended;
use App\Domains\Theatre\Contracts\Event\OperativeNoteCreated;
use App\Domains\Theatre\Domain\Exception\IllegalOperativeNoteTransition;
use App\Domains\Theatre\Domain\Exception\OperativeNoteAlreadyExistsForCase;
use App\Domains\Theatre\Domain\Exception\SurgicalCaseNotEligibleForOperativeNote;
use App\Domains\Theatre\Domain\Model\OperativeNote;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{tenantId: string, facilityId: string, actorId: string, caseId: string}
 */
function seedEligibleCase(?array $permissions = null, string $status = SurgicalCase::STATUS_IN_PROGRESS): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = theatreFixture($permissions);

    $case = createSurgicalCaseFixture(
        (string) $tenant->id,
        (string) $facility->id,
        (string) Str::uuid7(),
        (string) Str::uuid7(),
        ['status' => $status],
    );

    return [
        'tenantId' => (string) $tenant->id,
        'facilityId' => (string) $facility->id,
        'actorId' => (string) $user->id,
        'caseId' => (string) $case->id,
    ];
}

function dispatchCmd(string $tenantId, string $facilityId, object $command): mixed
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

test('CreateOperativeNote originates a DRAFT, stores content as a row, emits a PHI-free event, writes an audit row', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $caseId] = seedEligibleCase();

    $noteId = (string) Str::uuid7();
    dispatchCmd($t, $f, new CreateOperativeNote(
        noteId: $noteId,
        caseId: $caseId,
        actorId: $a,
        content: ['procedure' => 'Open appendectomy', 'findings' => 'Perforated appendix'],
    ));

    $note = TenantContextScope::run($t, $f, fn () => OperativeNote::query()->find($noteId));

    expect($note)->not->toBeNull()
        ->and($note->status)->toBe(OperativeNote::STATUS_DRAFT)
        ->and($note->content)->toEqual(['procedure' => 'Open appendectomy', 'findings' => 'Perforated appendix'])
        ->and((int) $note->version)->toBe(0);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', OperativeNoteCreated::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload)->not->toContain('appendectomy')
        ->and($outbox->payload)->not->toContain('Perforated');

    $audit = Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('target_id', $noteId)->where('action', 'theatre.operative_note_created')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->metadata)->not->toContain('appendectomy');
});

test('CreateOperativeNote is rejected when the case has not reached surgery', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $caseId] =
        seedEligibleCase(status: SurgicalCase::STATUS_SCHEDULED);

    expect(fn () => dispatchCmd($t, $f, new CreateOperativeNote(
        noteId: (string) Str::uuid7(),
        caseId: $caseId,
        actorId: $a,
    )))->toThrow(SurgicalCaseNotEligibleForOperativeNote::class);
});

test('a case may have at most one operative note (UNIQUE tenant_id, case_id)', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $caseId] = seedEligibleCase();

    dispatchCmd($t, $f, new CreateOperativeNote(noteId: (string) Str::uuid7(), caseId: $caseId, actorId: $a));

    expect(fn () => dispatchCmd($t, $f, new CreateOperativeNote(
        noteId: (string) Str::uuid7(),
        caseId: $caseId,
        actorId: $a,
    )))->toThrow(OperativeNoteAlreadyExistsForCase::class);
});

test('full lifecycle: DRAFT -> content edit -> REVIEWED -> SIGNED -> AMENDED', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $caseId] = seedEligibleCase();
    $noteId = (string) Str::uuid7();

    dispatchCmd($t, $f, new CreateOperativeNote(noteId: $noteId, caseId: $caseId, actorId: $a, content: ['v' => 1]));
    dispatchCmd($t, $f, new UpdateOperativeNoteContent(noteId: $noteId, actorId: $a, content: ['v' => 2]));
    dispatchCmd($t, $f, new ReviewOperativeNote(noteId: $noteId, actorId: $a));

    $note = TenantContextScope::run($t, $f, fn () => OperativeNote::query()->find($noteId));
    expect($note->status)->toBe(OperativeNote::STATUS_REVIEWED)
        ->and($note->content)->toEqual(['v' => 2])
        ->and($note->reviewed_by_user_id)->toBe($a)
        ->and($note->reviewed_at)->not->toBeNull();

    dispatchCmd($t, $f, new SignOperativeNote(noteId: $noteId, actorId: $a));

    $note = TenantContextScope::run($t, $f, fn () => OperativeNote::query()->find($noteId));
    expect($note->status)->toBe(OperativeNote::STATUS_SIGNED)
        ->and($note->signed_by_user_id)->toBe($a)
        ->and($note->signed_at)->not->toBeNull();

    dispatchCmd($t, $f, new AmendOperativeNote(
        amendmentId: (string) Str::uuid7(),
        noteId: $noteId,
        actorId: $a,
        amendmentText: 'Estimated blood loss corrected to 400ml.',
        reason: 'Transcription error.',
    ));

    $note = TenantContextScope::run($t, $f, fn () => OperativeNote::query()->find($noteId));
    expect($note->status)->toBe(OperativeNote::STATUS_AMENDED);
});

test('a note may be signed directly from DRAFT (REVIEWED is optional)', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $caseId] = seedEligibleCase();
    $noteId = (string) Str::uuid7();

    dispatchCmd($t, $f, new CreateOperativeNote(noteId: $noteId, caseId: $caseId, actorId: $a));
    dispatchCmd($t, $f, new SignOperativeNote(noteId: $noteId, actorId: $a));

    $note = TenantContextScope::run($t, $f, fn () => OperativeNote::query()->find($noteId));
    expect($note->status)->toBe(OperativeNote::STATUS_SIGNED);
});

test('content is frozen once the note is SIGNED', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $caseId] = seedEligibleCase();
    $noteId = (string) Str::uuid7();

    dispatchCmd($t, $f, new CreateOperativeNote(noteId: $noteId, caseId: $caseId, actorId: $a));
    dispatchCmd($t, $f, new SignOperativeNote(noteId: $noteId, actorId: $a));

    expect(fn () => dispatchCmd($t, $f, new UpdateOperativeNoteContent(
        noteId: $noteId,
        actorId: $a,
        content: ['late' => 'change'],
    )))->toThrow(IllegalOperativeNoteTransition::class);
});

test('amendments accumulate with an increasing sequence; text and reason live on the row, not the event', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $caseId] = seedEligibleCase();
    $noteId = (string) Str::uuid7();

    dispatchCmd($t, $f, new CreateOperativeNote(noteId: $noteId, caseId: $caseId, actorId: $a));
    dispatchCmd($t, $f, new SignOperativeNote(noteId: $noteId, actorId: $a));

    dispatchCmd($t, $f, new AmendOperativeNote(
        amendmentId: (string) Str::uuid7(), noteId: $noteId, actorId: $a,
        amendmentText: 'First correction.', reason: 'reason one',
    ));
    dispatchCmd($t, $f, new AmendOperativeNote(
        amendmentId: (string) Str::uuid7(), noteId: $noteId, actorId: $a,
        amendmentText: 'Second correction.', reason: 'reason two',
    ));

    $rows = Tenancy::run($t, fn () => DB::table('thr_operative_note_amendments')
        ->where('operative_note_id', $noteId)->orderBy('sequence')->get());
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->sequence)->toBe(1)
        ->and($rows[1]->sequence)->toBe(2)
        ->and($rows[0]->amendment_text)->toBe('First correction.');

    $amendedEvents = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', OperativeNoteAmended::class)->get());
    expect($amendedEvents)->toHaveCount(2);
    foreach ($amendedEvents as $event) {
        expect($event->payload)->not->toContain('correction')
            ->and($event->payload)->not->toContain('reason');
    }
});

test('AmendOperativeNote is rejected before the note is signed', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $caseId] = seedEligibleCase();
    $noteId = (string) Str::uuid7();

    dispatchCmd($t, $f, new CreateOperativeNote(noteId: $noteId, caseId: $caseId, actorId: $a));

    expect(fn () => dispatchCmd($t, $f, new AmendOperativeNote(
        amendmentId: (string) Str::uuid7(), noteId: $noteId, actorId: $a,
        amendmentText: 'x', reason: 'y',
    )))->toThrow(IllegalOperativeNoteTransition::class);
});

test('signing requires theatre.operative_notes.sign — the author key alone is not enough', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $caseId] = seedEligibleCase(permissions: [
        'theatre.access',
        'theatre.operative_notes.author',
    ]);
    $noteId = (string) Str::uuid7();

    dispatchCmd($t, $f, new CreateOperativeNote(noteId: $noteId, caseId: $caseId, actorId: $a));

    expect(fn () => dispatchCmd($t, $f, new SignOperativeNote(noteId: $noteId, actorId: $a)))
        ->toThrow(AuthorizationException::class);
});

test('creating a note requires theatre.operative_notes.author', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $caseId] = seedEligibleCase(permissions: [
        'theatre.access',
    ]);

    expect(fn () => dispatchCmd($t, $f, new CreateOperativeNote(
        noteId: (string) Str::uuid7(),
        caseId: $caseId,
        actorId: $a,
    )))->toThrow(AuthorizationException::class);
});

test('GetOperativeNote returns the summary with its amendment history for an authorized reader', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $caseId] = seedEligibleCase();
    $noteId = (string) Str::uuid7();

    dispatchCmd($t, $f, new CreateOperativeNote(noteId: $noteId, caseId: $caseId, actorId: $a, content: ['k' => 'v']));
    dispatchCmd($t, $f, new SignOperativeNote(noteId: $noteId, actorId: $a));
    dispatchCmd($t, $f, new AmendOperativeNote(
        amendmentId: (string) Str::uuid7(), noteId: $noteId, actorId: $a,
        amendmentText: 'Addendum.', reason: 'more detail',
    ));

    $summary = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetOperativeNote($noteId)));

    expect($summary)->toBeInstanceOf(OperativeNoteSummary::class)
        ->and($summary->operativeNoteId)->toBe($noteId)
        ->and($summary->caseId)->toBe($caseId)
        ->and($summary->status)->toBe(OperativeNote::STATUS_AMENDED)
        ->and($summary->content)->toEqual(['k' => 'v'])
        ->and($summary->amendments)->toHaveCount(1)
        ->and($summary->amendments[0]->sequence)->toBe(1)
        ->and($summary->amendments[0]->amendmentText)->toBe('Addendum.');
});

test('GetOperativeNoteForCase returns null when the case has no note, the summary when it does', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $caseId] = seedEligibleCase();

    $none = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetOperativeNoteForCase($caseId)));
    expect($none)->toBeNull();

    $noteId = (string) Str::uuid7();
    dispatchCmd($t, $f, new CreateOperativeNote(noteId: $noteId, caseId: $caseId, actorId: $a));

    $summary = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetOperativeNoteForCase($caseId)));
    expect($summary)->toBeInstanceOf(OperativeNoteSummary::class)
        ->and($summary->operativeNoteId)->toBe($noteId);
});

test('GetOperativeNote hides a note at a facility outside the reader scope', function () {
    ['tenantId' => $t, 'facilityId' => $f, 'actorId' => $a, 'caseId' => $caseId] = seedEligibleCase();
    $noteId = (string) Str::uuid7();
    dispatchCmd($t, $f, new CreateOperativeNote(noteId: $noteId, caseId: $caseId, actorId: $a));

    // a fresh reader in another tenant/facility
    ['tenant' => $other, 'facility' => $otherFacility] = theatreFixture();

    expect(fn () => TenantContextScope::run((string) $other->id, (string) $otherFacility->id, fn () => app(QueryBus::class)->dispatch(new GetOperativeNote($noteId))))
        ->toThrow(NotFound::class);
});
