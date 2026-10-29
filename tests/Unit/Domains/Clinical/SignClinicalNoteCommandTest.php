<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\SignClinicalNote;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.1) unit gate — the SignClinicalNote command DTO (IC §3, §5, §18).
 */

test('it is a per-subject command — subjectId is the note, ability "sign", Auditable + AuthorizesAgainst', function () {
    $noteId = (string) Str::uuid7();
    $command = new SignClinicalNote($noteId, (string) Str::uuid7());

    expect($command)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($command)->toBeInstanceOf(Auditable::class)
        ->and($command->policyAbility())->toBe('sign')
        ->and($command->subjectId())->toBe($noteId);
});

test('auditEntry() -> clinical.note_signed, null reason, empty metadata', function () {
    $noteId = (string) Str::uuid7();
    $actorId = (string) Str::uuid7();
    $entry = (new SignClinicalNote($noteId, $actorId))->auditEntry();

    expect($entry->action)->toBe('clinical.note_signed')
        ->and($entry->actorId)->toBe($actorId)
        ->and($entry->targetType)->toBe('clinical_note')
        ->and($entry->targetId)->toBe($noteId)
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('it carries exactly clinicalNoteId + actorId and is final readonly', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(SignClinicalNote::class, '__construct'))->getParameters(),
    );
    expect($params)->toBe(['clinicalNoteId', 'actorId']);

    $reflection = new ReflectionClass(SignClinicalNote::class);
    expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
});

test('it rejects malformed ids (IC §3)', function () {
    expect(fn () => new SignClinicalNote('nope', (string) Str::uuid7()))->toThrow(InvalidArgumentException::class);
    expect(fn () => new SignClinicalNote((string) Str::uuid7(), 'nope'))->toThrow(InvalidArgumentException::class);
});
