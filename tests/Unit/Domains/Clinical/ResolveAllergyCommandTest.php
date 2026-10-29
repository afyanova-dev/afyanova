<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\ResolveAllergy;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.5) unit gate — the ResolveAllergy command DTO (IC §3, §5, §18;
 | ADR-132, ADR-141 D-K / D-D).
 */

function resolveAllergyArgs(array $overrides = []): array
{
    return array_merge([
        'allergyId' => (string) Str::uuid7(),
        'actorId' => (string) Str::uuid7(),
    ], $overrides);
}

test('it is a per-subject command — subjectId = allergyId, ability "resolve", Auditable + AuthorizesAgainst', function () {
    $c = new ResolveAllergy(...resolveAllergyArgs());

    expect($c)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($c)->toBeInstanceOf(Auditable::class)
        ->and($c->policyAbility())->toBe('resolve')
        ->and($c->subjectId())->toBe($c->allergyId);
});

test('auditEntry() -> clinical.allergies.resolved; empty metadata; backdate reason handled', function () {
    $args = resolveAllergyArgs();
    $entry = (new ResolveAllergy(...$args))->auditEntry();

    expect($entry->action)->toBe('clinical.allergies.resolved')
        ->and($entry->actorId)->toBe($args['actorId'])
        ->and($entry->targetType)->toBe('allergy')
        ->and($entry->targetId)->toBe($args['allergyId'])
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('it rejects malformed uuids (IC §3)', function (string $field) {
    expect(fn () => new ResolveAllergy(...resolveAllergyArgs([$field => 'nope'])))->toThrow(InvalidArgumentException::class);
})->with(['allergyId', 'actorId']);

test('it rejects a future resolved_at', function () {
    expect(fn () => new ResolveAllergy(...resolveAllergyArgs(['resolvedAt' => CarbonImmutable::now()->addDay()->toISOString()])))
        ->toThrow(InvalidArgumentException::class, 'future');
});

test('backdating resolved_at > 24h needs a reason (ADR-132 applies to resolved_at, ADR-141 D-H)', function () {
    $backdated = CarbonImmutable::now()->subHours(30)->toISOString();

    expect(fn () => new ResolveAllergy(...resolveAllergyArgs(['resolvedAt' => $backdated])))
        ->toThrow(InvalidArgumentException::class, 'reason');

    $c = new ResolveAllergy(...resolveAllergyArgs(['resolvedAt' => $backdated, 'reason' => 'outgrown in childhood, documented today']));
    expect($c->auditEntry()->reason)->toBe('outgrown in childhood, documented today');

    $recent = new ResolveAllergy(...resolveAllergyArgs(['resolvedAt' => CarbonImmutable::now()->subHours(2)->toISOString()]));
    expect($recent->auditEntry()->reason)->toBeNull();
});

test('the command is final readonly (IC §5)', function () {
    $r = new ReflectionClass(ResolveAllergy::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
});
