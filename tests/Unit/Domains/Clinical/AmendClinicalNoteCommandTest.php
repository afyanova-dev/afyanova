<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AmendClinicalNote;
use App\Domains\Clinical\Domain\Enum\ClinicalNoteAmendmentType;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.1) unit gate — the AmendClinicalNote command DTO (IC §3, §5, §18).
 */

function validAmendArgs(array $overrides = []): array
{
    return array_merge([
        'clinicalNoteId' => (string) Str::uuid7(),
        'actorId' => (string) Str::uuid7(),
        'amendmentType' => 'ADDENDUM',
        'body' => 'Additional finding on review.',
        'reason' => 'new information from lab',
    ], $overrides);
}

test('it is a per-subject command — subjectId is the note, ability "amend", Auditable + AuthorizesAgainst', function () {
    $command = new AmendClinicalNote(...validAmendArgs());

    expect($command)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($command)->toBeInstanceOf(Auditable::class)
        ->and($command->policyAbility())->toBe('amend')
        ->and($command->subjectId())->toBe($command->clinicalNoteId)
        ->and($command->amendmentTypeEnum())->toBe(ClinicalNoteAmendmentType::Addendum);
});

test('auditEntry() -> clinical.note_amended, null reason (the amendment reason is clinical data, not audit metadata), empty metadata', function () {
    $args = validAmendArgs();
    $entry = (new AmendClinicalNote(...$args))->auditEntry();

    expect($entry->action)->toBe('clinical.note_amended')
        ->and($entry->actorId)->toBe($args['actorId'])
        ->and($entry->targetType)->toBe('clinical_note')
        ->and($entry->targetId)->toBe($args['clinicalNoteId'])
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('it rejects a blank body / blank reason (reason is REQUIRED — ADR-134)', function (array $overrides) {
    expect(fn () => new AmendClinicalNote(...validAmendArgs($overrides)))->toThrow(InvalidArgumentException::class);
})->with([
    'blank body' => [['body' => '  ']],
    'blank reason' => [['reason' => '']],
]);

test('it rejects an unknown amendment type', function () {
    expect(fn () => new AmendClinicalNote(...validAmendArgs(['amendmentType' => 'NONSENSE'])))
        ->toThrow(InvalidArgumentException::class);
});

test('it rejects a future occurred_at', function () {
    expect(fn () => new AmendClinicalNote(...validAmendArgs([
        'occurredAt' => CarbonImmutable::now()->addHour()->toISOString(),
    ])))->toThrow(InvalidArgumentException::class, 'future');
});

test('it rejects malformed ids and is final readonly', function () {
    expect(fn () => new AmendClinicalNote(...validAmendArgs(['clinicalNoteId' => 'nope'])))
        ->toThrow(InvalidArgumentException::class);

    $reflection = new ReflectionClass(AmendClinicalNote::class);
    expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
});
