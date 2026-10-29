<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CreateClinicalNote;
use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.1) unit gate — the CreateClinicalNote command DTO
 | (IC §3, §5, §18; ADR-132, ADR-134).
 */

function validCreateArgs(array $overrides = []): array
{
    return array_merge([
        'clinicalNoteId' => (string) Str::uuid7(),
        'actorId' => (string) Str::uuid7(),
        'patientId' => (string) Str::uuid7(),
        'encounterId' => (string) Str::uuid7(),
        'noteType' => 'progress_note',
        'title' => 'Progress note',
        'body' => 'Patient stable.',
    ], $overrides);
}

test('it is a class-level create command — subjectId null, ability "create", Auditable + AuthorizesAgainst', function () {
    $command = new CreateClinicalNote(...validCreateArgs());

    expect($command)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($command)->toBeInstanceOf(Auditable::class)
        ->and($command->policyAbility())->toBe('create')
        ->and($command->subjectId())->toBeNull();
});

test('auditEntry() targets clinical_note with action clinical.note_created and empty metadata', function () {
    $args = validCreateArgs();
    $entry = (new CreateClinicalNote(...$args))->auditEntry();

    expect($entry->action)->toBe('clinical.note_created')
        ->and($entry->actorId)->toBe($args['actorId'])
        ->and($entry->targetType)->toBe('clinical_note')
        ->and($entry->targetId)->toBe($args['clinicalNoteId'])
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('it rejects malformed uuids (IC §3)', function (string $field) {
    expect(fn () => new CreateClinicalNote(...validCreateArgs([$field => 'nope'])))
        ->toThrow(InvalidArgumentException::class);
})->with(['clinicalNoteId', 'actorId', 'patientId', 'encounterId']);

test('it rejects a blank note type / body', function (array $overrides) {
    expect(fn () => new CreateClinicalNote(...validCreateArgs($overrides)))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'blank note type' => [['noteType' => '   ']],
    'blank body' => [['body' => '']],
    'blank title' => [['title' => '  ']],
]);

test('it rejects a future occurred_at', function () {
    expect(fn () => new CreateClinicalNote(...validCreateArgs([
        'occurredAt' => CarbonImmutable::now()->addDay()->toISOString(),
    ])))->toThrow(InvalidArgumentException::class, 'future');
});

test('it rejects an unparseable occurred_at', function () {
    expect(fn () => new CreateClinicalNote(...validCreateArgs(['occurredAt' => 'not-a-date'])))
        ->toThrow(InvalidArgumentException::class);
});

test('backdating more than 24h without a reason is rejected; with a reason it is accepted and the reason lands on the audit entry', function () {
    $backdated = CarbonImmutable::now()->subHours(25)->toISOString();

    expect(fn () => new CreateClinicalNote(...validCreateArgs(['occurredAt' => $backdated])))
        ->toThrow(InvalidArgumentException::class, 'reason');

    $command = new CreateClinicalNote(...validCreateArgs([
        'occurredAt' => $backdated,
        'reason' => 'documented from paper notes after clinic',
    ]));

    expect($command->auditEntry()->reason)->toBe('documented from paper notes after clinic');
});

// ── authored context (ADR-144 — C.8 nursing note) ──────────────────

test('authoredContext defaults to CLINICIAN when omitted (the C.1 behaviour — backward compatible)', function () {
    $command = new CreateClinicalNote(...validCreateArgs());

    expect($command->authoredContext)->toBe('CLINICIAN')
        ->and($command->authoredContext)->toBe(ClinicalNote::AUTHORED_CONTEXT_CLINICIAN);
});

test('authoredContext accepts CLINICIAN and NURSING and rejects anything else (ADR-144)', function () {
    expect((new CreateClinicalNote(...validCreateArgs(['authoredContext' => 'CLINICIAN'])))->authoredContext)->toBe('CLINICIAN')
        ->and((new CreateClinicalNote(...validCreateArgs(['authoredContext' => 'NURSING'])))->authoredContext)->toBe('NURSING');

    foreach (['PROCEDURE', 'nurse', 'nursing', '', 'PATIENT'] as $bad) {
        expect(fn () => new CreateClinicalNote(...validCreateArgs(['authoredContext' => $bad])))
            ->toThrow(InvalidArgumentException::class, 'CLINICIAN or NURSING');
    }
});

test('authoredContext does not appear on the audit entry (documentation metadata, not audit)', function () {
    $entry = (new CreateClinicalNote(...validCreateArgs(['authoredContext' => 'NURSING'])))->auditEntry();

    expect($entry->action)->toBe('clinical.note_created')
        ->and($entry->metadata)->toBe([])
        ->and($entry->reason)->toBeNull();
});

test('backdating within 24h needs no reason', function () {
    $command = new CreateClinicalNote(...validCreateArgs([
        'occurredAt' => CarbonImmutable::now()->subHours(3)->toISOString(),
    ]));

    expect($command->auditEntry()->reason)->toBeNull();
});

test('the command is final readonly (IC §5)', function () {
    $reflection = new ReflectionClass(CreateClinicalNote::class);
    expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
});
