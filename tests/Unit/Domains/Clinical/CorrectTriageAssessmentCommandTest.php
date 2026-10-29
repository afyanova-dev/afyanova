<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectTriageAssessment;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.9) unit gate — the CorrectTriageAssessment command DTO
 | (IC §3, §5, §18; ADR-145 D-F / D-G).
 */

function validCorrectTriageArgs(array $overrides = []): array
{
    return array_merge([
        'newTriageAssessmentId' => (string) Str::uuid7(),
        'supersedesTriageAssessmentId' => (string) Str::uuid7(),
        'actorId' => (string) Str::uuid7(),
        'chiefComplaint' => 'Chest pain, worsening',
        'triagePriority' => 'EMERGENCY',
        'reason' => 'patient deteriorated',
    ], $overrides);
}

test('it is a per-subject correct command — subjectId is the superseded id, ability "correct"', function () {
    $args = validCorrectTriageArgs();
    $command = new CorrectTriageAssessment(...$args);

    expect($command)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($command)->toBeInstanceOf(Auditable::class)
        ->and($command->policyAbility())->toBe('correct')
        ->and($command->subjectId())->toBe($args['supersedesTriageAssessmentId']);
});

test('auditEntry() targets the NEW id with action clinical.triage.corrected, reason null (rationale is on the row)', function () {
    $args = validCorrectTriageArgs();
    $entry = (new CorrectTriageAssessment(...$args))->auditEntry();

    expect($entry->action)->toBe('clinical.triage.corrected')
        ->and($entry->targetType)->toBe('triage_assessment')
        ->and($entry->targetId)->toBe($args['newTriageAssessmentId'])
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('it rejects a self-supersede', function () {
    $id = (string) Str::uuid7();
    expect(fn () => new CorrectTriageAssessment(...validCorrectTriageArgs([
        'newTriageAssessmentId' => $id, 'supersedesTriageAssessmentId' => $id,
    ])))->toThrow(InvalidArgumentException::class, 'cannot supersede itself');
});

test('it requires a non-blank reason (ADR-145 D-F)', function () {
    expect(fn () => new CorrectTriageAssessment(...validCorrectTriageArgs(['reason' => '   '])))
        ->toThrow(InvalidArgumentException::class, 'reason is required');
});

test('it rejects malformed uuids and an out-of-set priority', function () {
    expect(fn () => new CorrectTriageAssessment(...validCorrectTriageArgs(['actorId' => 'nope'])))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new CorrectTriageAssessment(...validCorrectTriageArgs(['triagePriority' => 'CRITICAL'])))
        ->toThrow(InvalidArgumentException::class, 'EMERGENCY / URGENT / HIGH / NORMAL / LOW');
});

test('it rejects a future occurred_at but there is no > 24h backdate check (the reason is the justification)', function () {
    expect(fn () => new CorrectTriageAssessment(...validCorrectTriageArgs([
        'occurredAt' => CarbonImmutable::now()->addHour()->toISOString(),
    ])))->toThrow(InvalidArgumentException::class, 'future');

    $command = new CorrectTriageAssessment(...validCorrectTriageArgs([
        'occurredAt' => CarbonImmutable::now()->subDays(5)->toISOString(),
    ]));
    expect($command->occurredAt)->toBeString();
});

test('the command is final readonly (IC §5)', function () {
    $reflection = new ReflectionClass(CorrectTriageAssessment::class);
    expect($reflection->isFinal())->toBeTrue()->and($reflection->isReadOnly())->toBeTrue();
});
