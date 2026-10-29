<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AddProblem;
use App\Domains\Clinical\Domain\ValueObject\ProblemCode;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.4) unit gate — the AddProblem command DTO (IC §3, §5, §18;
 | ADR-132, ADR-140 D-A … D-R).
 */

function addProblemArgs(array $overrides = []): array
{
    return array_merge([
        'problemId' => (string) Str::uuid7(),
        'actorId' => (string) Str::uuid7(),
        'patientId' => (string) Str::uuid7(),
        'encounterId' => (string) Str::uuid7(),
        'coding' => ProblemCode::of(code: 'I10', codeSystem: 'ICD-10'),
        'description' => 'Essential (primary) hypertension',
    ], $overrides);
}

test('it is a class-level create command — subjectId null, ability "record", Auditable + AuthorizesAgainst', function () {
    $c = new AddProblem(...addProblemArgs());

    expect($c)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($c)->toBeInstanceOf(Auditable::class)
        ->and($c->policyAbility())->toBe('record')
        ->and($c->subjectId())->toBeNull();
});

test('auditEntry() -> clinical.problems.added; no description / code / onset in metadata', function () {
    $args = addProblemArgs(['onsetDate' => '2019-03-01']);
    $entry = (new AddProblem(...$args))->auditEntry();

    expect($entry->action)->toBe('clinical.problems.added')
        ->and($entry->actorId)->toBe($args['actorId'])
        ->and($entry->targetType)->toBe('problem')
        ->and($entry->targetId)->toBe($args['problemId'])
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('it rejects malformed uuids (IC §3)', function (string $field) {
    expect(fn () => new AddProblem(...addProblemArgs([$field => 'nope'])))->toThrow(InvalidArgumentException::class);
})->with(['problemId', 'actorId', 'patientId', 'encounterId', 'sourceDiagnosisId']);

test('sourceDiagnosisId is optional (null) and, when present, must be a UUID', function () {
    expect((new AddProblem(...addProblemArgs()))->sourceDiagnosisId)->toBeNull();
    $id = (string) Str::uuid7();
    expect((new AddProblem(...addProblemArgs(['sourceDiagnosisId' => $id])))->sourceDiagnosisId)->toBe($id);
});

test('it rejects a blank description (ADR-140 D-H)', function () {
    expect(fn () => new AddProblem(...addProblemArgs(['description' => '   '])))->toThrow(InvalidArgumentException::class);
});

test('it rejects a future onset_date but accepts any past date without a reason (ADR-140 D-F)', function () {
    expect(fn () => new AddProblem(...addProblemArgs(['onsetDate' => CarbonImmutable::now()->addDay()->toDateString()])))
        ->toThrow(InvalidArgumentException::class, 'future');

    expect((new AddProblem(...addProblemArgs(['onsetDate' => '1990-01-01'])))->onsetDate)->toBe('1990-01-01');
});

test('it rejects a future occurred_at', function () {
    expect(fn () => new AddProblem(...addProblemArgs(['occurredAt' => CarbonImmutable::now()->addDay()->toISOString()])))
        ->toThrow(InvalidArgumentException::class, 'future');
});

test('backdating occurred_at > 24h needs a reason (-> audit row); within 24h needs none', function () {
    $backdated = CarbonImmutable::now()->subHours(30)->toISOString();

    expect(fn () => new AddProblem(...addProblemArgs(['occurredAt' => $backdated])))
        ->toThrow(InvalidArgumentException::class, 'reason');

    $c = new AddProblem(...addProblemArgs(['occurredAt' => $backdated, 'reason' => 'documented from a prior discharge summary']));
    expect($c->auditEntry()->reason)->toBe('documented from a prior discharge summary');

    $recent = new AddProblem(...addProblemArgs(['occurredAt' => CarbonImmutable::now()->subHours(2)->toISOString()]));
    expect($recent->auditEntry()->reason)->toBeNull();
});

test('the command is final readonly (IC §5)', function () {
    $r = new ReflectionClass(AddProblem::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
});
