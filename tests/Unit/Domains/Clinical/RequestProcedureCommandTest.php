<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\RequestProcedure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (CP.1) unit gate — RequestProcedure command-level validation
 | (ADR-146 D-G). No DB.
 */

function validRequestProcedureArgs(array $o = []): array
{
    return array_merge([
        'procedureId' => (string) Str::uuid7(),
        'actorId' => (string) Str::uuid7(),
        'patientId' => (string) Str::uuid7(),
        'encounterId' => (string) Str::uuid7(),
        'definitionId' => (string) Str::uuid7(),
    ], $o);
}

test('rejects a malformed uuid on every identifier field (IC §3)', function (string $field) {
    expect(fn () => new RequestProcedure(...validRequestProcedureArgs([$field => 'not-a-uuid'])))
        ->toThrow(InvalidArgumentException::class);
})->with(['procedureId', 'actorId', 'patientId', 'encounterId', 'definitionId']);

test('rejects a malformed sourceDiagnosisId / sourceClinicalPlanId when supplied', function () {
    expect(fn () => new RequestProcedure(...validRequestProcedureArgs(['sourceDiagnosisId' => 'nope'])))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new RequestProcedure(...validRequestProcedureArgs(['sourceClinicalPlanId' => 'nope'])))
        ->toThrow(InvalidArgumentException::class);
});

test('defaults authoredContext to CLINICIAN and rejects an out-of-set value', function () {
    $cmd = new RequestProcedure(...validRequestProcedureArgs());
    expect($cmd->authoredContext)->toBe('CLINICIAN');

    expect(fn () => new RequestProcedure(...validRequestProcedureArgs(['authoredContext' => 'PROCEDURE'])))
        ->toThrow(InvalidArgumentException::class, 'authoredContext');
});

test('rejects a future occurredAt', function () {
    expect(fn () => new RequestProcedure(...validRequestProcedureArgs([
        'occurredAt' => CarbonImmutable::now()->addDay()->toISOString(),
    ])))->toThrow(InvalidArgumentException::class, 'future');
});

test('requires a reason when occurredAt is more than 24h in the past (ADR-132)', function () {
    expect(fn () => new RequestProcedure(...validRequestProcedureArgs([
        'occurredAt' => CarbonImmutable::now()->subDays(2)->toISOString(),
    ])))->toThrow(InvalidArgumentException::class, 'reason is required');

    $cmd = new RequestProcedure(...validRequestProcedureArgs([
        'occurredAt' => CarbonImmutable::now()->subDays(2)->toISOString(),
        'reason' => 'documented late from the paper chart',
    ]));
    expect($cmd->reason)->toBe('documented late from the paper chart');
});

test('policyAbility is "request" and subjectId is null (class-level create check)', function () {
    $cmd = new RequestProcedure(...validRequestProcedureArgs());
    expect($cmd->policyAbility())->toBe('request')->and($cmd->subjectId())->toBeNull();
});

test('auditEntry names the procedure and carries the backdate reason only', function () {
    $cmd = new RequestProcedure(...validRequestProcedureArgs());
    $entry = $cmd->auditEntry();
    expect($entry->action)->toBe('procedures.requested')
        ->and($entry->targetType)->toBe('clinical_procedure')
        ->and($entry->targetId)->toBe($cmd->procedureId)
        ->and($entry->reason)->toBeNull();
});
