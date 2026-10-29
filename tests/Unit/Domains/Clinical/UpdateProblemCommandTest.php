<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\UpdateProblem;
use App\Domains\Clinical\Domain\ValueObject\ProblemChangeType;
use App\Domains\Clinical\Domain\ValueObject\ProblemCode;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.4) unit gate — the UpdateProblem command DTO (IC §3, §5, §18;
 | ADR-140 D-J). REVISION | CORRECTION; correction reason mandatory; audit
 | reason always null (the rationale is clinical data on the history row).
 */

function updateProblemArgs(array $overrides = []): array
{
    return array_merge([
        'problemId' => (string) Str::uuid7(),
        'actorId' => (string) Str::uuid7(),
        'changeType' => ProblemChangeType::REVISION,
        'coding' => ProblemCode::of(code: 'I10', codeSystem: 'ICD-10'),
        'description' => 'Essential (primary) hypertension',
    ], $overrides);
}

test('it is a per-subject command — subjectId = problemId, ability "update", Auditable + AuthorizesAgainst', function () {
    $args = updateProblemArgs();
    $c = new UpdateProblem(...$args);

    expect($c)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($c)->toBeInstanceOf(Auditable::class)
        ->and($c->policyAbility())->toBe('update')
        ->and($c->subjectId())->toBe($args['problemId']);
});

test('CORRECTION requires a non-blank reason (ADR-140 D-J)', function () {
    expect(fn () => new UpdateProblem(...updateProblemArgs(['changeType' => ProblemChangeType::CORRECTION])))
        ->toThrow(InvalidArgumentException::class, 'reason');
    expect(fn () => new UpdateProblem(...updateProblemArgs(['changeType' => ProblemChangeType::CORRECTION, 'reason' => '   '])))
        ->toThrow(InvalidArgumentException::class);

    $c = new UpdateProblem(...updateProblemArgs(['changeType' => ProblemChangeType::CORRECTION, 'reason' => 'reclassified after echo']));
    expect($c->correctionReason())->toBe('reclassified after echo');
});

test('REVISION does not require a reason and correctionReason() is null even if a reason is passed', function () {
    $c = new UpdateProblem(...updateProblemArgs(['changeType' => ProblemChangeType::REVISION, 'reason' => 'noise']));
    expect($c->correctionReason())->toBeNull();
});

test('auditEntry() -> clinical.problems.updated with a NULL reason and empty metadata (rationale lives on the history row)', function () {
    $args = updateProblemArgs(['changeType' => ProblemChangeType::CORRECTION, 'reason' => 'sensitive clinical rationale']);
    $entry = (new UpdateProblem(...$args))->auditEntry();

    expect($entry->action)->toBe('clinical.problems.updated')
        ->and($entry->targetType)->toBe('problem')
        ->and($entry->targetId)->toBe($args['problemId'])
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('it rejects malformed uuids and a blank description', function () {
    expect(fn () => new UpdateProblem(...updateProblemArgs(['problemId' => 'nope'])))->toThrow(InvalidArgumentException::class);
    expect(fn () => new UpdateProblem(...updateProblemArgs(['actorId' => 'nope'])))->toThrow(InvalidArgumentException::class);
    expect(fn () => new UpdateProblem(...updateProblemArgs(['description' => '  '])))->toThrow(InvalidArgumentException::class);
});

test('it rejects a future onset_date', function () {
    expect(fn () => new UpdateProblem(...updateProblemArgs(['onsetDate' => CarbonImmutable::now()->addDay()->toDateString()])))
        ->toThrow(InvalidArgumentException::class, 'future');
});

test('there is no occurredAt / status / facility / encounter parameter on the command', function () {
    $params = array_map(
        fn (ReflectionParameter $p) => $p->getName(),
        (new ReflectionClass(UpdateProblem::class))->getConstructor()->getParameters(),
    );
    expect($params)->toBe(['problemId', 'actorId', 'changeType', 'coding', 'description', 'onsetDate', 'reason']);
});

test('the command is final readonly (IC §5)', function () {
    $r = new ReflectionClass(UpdateProblem::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
});
