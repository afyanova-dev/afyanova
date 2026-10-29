<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CancelClinicalNote;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.1) unit gate — the CancelClinicalNote command DTO. LOCKED
 | behaviour (authorization spec §2): AuthorizesAgainst ONLY (NOT Auditable),
 | gated by clinical.notes.amend, no reason field, no event, no audit.
 */

test('it is a per-subject command — subjectId is the note, ability "cancel"', function () {
    $noteId = (string) Str::uuid7();
    $command = new CancelClinicalNote($noteId, (string) Str::uuid7());

    expect($command)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($command->policyAbility())->toBe('cancel')
        ->and($command->subjectId())->toBe($noteId);
});

test('it is NOT Auditable (draft cancellation is not audited in PHASE 6 — ADR-134)', function () {
    expect(new CancelClinicalNote((string) Str::uuid7(), (string) Str::uuid7()))
        ->not->toBeInstanceOf(Auditable::class);

    expect(method_exists(CancelClinicalNote::class, 'auditEntry'))->toBeFalse();
});

test('it carries exactly clinicalNoteId + actorId — NO reason field', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(CancelClinicalNote::class, '__construct'))->getParameters(),
    );

    expect($params)->toBe(['clinicalNoteId', 'actorId'])
        ->and($params)->not->toContain('reason');
});

test('it is final readonly and rejects malformed ids (IC §3, §5)', function () {
    $reflection = new ReflectionClass(CancelClinicalNote::class);
    expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();

    expect(fn () => new CancelClinicalNote('nope', (string) Str::uuid7()))->toThrow(InvalidArgumentException::class);
});
