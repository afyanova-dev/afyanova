<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\ResolveProblem;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.4) unit gate — the ResolveProblem command DTO (IC §3, §5, §18;
 | ADR-132, ADR-140 D-E).
 */

function resolveProblemArgs(array $overrides = []): array
{
    return array_merge([
        'problemId' => (string) Str::uuid7(),
        'actorId' => (string) Str::uuid7(),
    ], $overrides);
}

test('it is a per-subject command — subjectId = problemId, ability "resolve", Auditable + AuthorizesAgainst', function () {
    $args = resolveProblemArgs();
    $c = new ResolveProblem(...$args);

    expect($c)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($c)->toBeInstanceOf(Auditable::class)
        ->and($c->policyAbility())->toBe('resolve')
        ->and($c->subjectId())->toBe($args['problemId']);
});

test('auditEntry() -> clinical.problems.resolved; empty metadata; backdate reason carried when present', function () {
    $entry = (new ResolveProblem(...resolveProblemArgs()))->auditEntry();
    expect($entry->action)->toBe('clinical.problems.resolved')
        ->and($entry->targetType)->toBe('problem')
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);

    $backdated = new ResolveProblem(...resolveProblemArgs([
        'resolvedAt' => CarbonImmutable::now()->subDays(3)->toISOString(),
        'reason' => 'resolution noted late from the ward round',
    ]));
    expect($backdated->auditEntry()->reason)->toBe('resolution noted late from the ward round');
});

test('it rejects malformed uuids (IC §3)', function (string $field) {
    expect(fn () => new ResolveProblem(...resolveProblemArgs([$field => 'nope'])))->toThrow(InvalidArgumentException::class);
})->with(['problemId', 'actorId']);

test('it rejects a future resolved_at', function () {
    expect(fn () => new ResolveProblem(...resolveProblemArgs(['resolvedAt' => CarbonImmutable::now()->addDay()->toISOString()])))
        ->toThrow(InvalidArgumentException::class, 'future');
});

test('backdating resolved_at > 24h needs a reason (ADR-132 applies to resolved_at — D-F); within 24h needs none', function () {
    expect(fn () => new ResolveProblem(...resolveProblemArgs(['resolvedAt' => CarbonImmutable::now()->subHours(30)->toISOString()])))
        ->toThrow(InvalidArgumentException::class, 'reason');

    $recent = new ResolveProblem(...resolveProblemArgs(['resolvedAt' => CarbonImmutable::now()->subHours(2)->toISOString()]));
    expect($recent->auditEntry()->reason)->toBeNull();
});

test('the command is final readonly (IC §5)', function () {
    $r = new ReflectionClass(ResolveProblem::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
});
