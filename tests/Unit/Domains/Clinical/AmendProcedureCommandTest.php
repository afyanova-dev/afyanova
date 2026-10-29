<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AmendProcedure;
use Illuminate\Support\Str;

/*
 | PHASE 6 (CP.1) unit gate — AmendProcedure command-level validation
 | (ADR-146 D-M). No DB.
 */

test('requires at least one amendable field to be supplied', function () {
    expect(fn () => new AmendProcedure(
        procedureId: (string) Str::uuid7(), actorId: (string) Str::uuid7(),
        amendmentType: 'CORRECTION', body: 'note', reason: 'why',
    ))->toThrow(InvalidArgumentException::class, 'at least one amendable field');
});

test('accepts a single supplied field and reports it in changedFields()', function () {
    $cmd = new AmendProcedure(
        procedureId: (string) Str::uuid7(), actorId: (string) Str::uuid7(),
        amendmentType: 'CORRECTION', body: 'note', reason: 'why',
        outcome: 'SUCCESSFUL',
    );

    expect($cmd->changedFields())->toBe(['outcome' => 'SUCCESSFUL']);
});

test('changedFields() includes every supplied non-null field', function () {
    $cmd = new AmendProcedure(
        procedureId: (string) Str::uuid7(), actorId: (string) Str::uuid7(),
        amendmentType: 'CORRECTION', body: 'note', reason: 'why',
        outcome: 'SUCCESSFUL', site: 'left arm', statusReason: 'n/a',
    );

    expect($cmd->changedFields())->toBe(['outcome' => 'SUCCESSFUL', 'site' => 'left arm', 'statusReason' => 'n/a']);
});

test('rejects an out-of-set amendmentType, a blank body, and a blank reason', function () {
    $base = ['procedureId' => (string) Str::uuid7(), 'actorId' => (string) Str::uuid7(), 'outcome' => 'SUCCESSFUL'];

    expect(fn () => new AmendProcedure(...$base, amendmentType: 'RETRACTION', body: 'x', reason: 'y'))
        ->toThrow(InvalidArgumentException::class, 'ProcedureAmendmentType');
    expect(fn () => new AmendProcedure(...$base, amendmentType: 'CORRECTION', body: '  ', reason: 'y'))
        ->toThrow(InvalidArgumentException::class, 'body');
    expect(fn () => new AmendProcedure(...$base, amendmentType: 'CORRECTION', body: 'x', reason: '  '))
        ->toThrow(InvalidArgumentException::class, 'reason is required');
});

test('policyAbility is "amend" and subjectId is the procedureId (per-subject)', function () {
    $id = (string) Str::uuid7();
    $cmd = new AmendProcedure(
        procedureId: $id,
        actorId: (string) Str::uuid7(), amendmentType: 'CORRECTION', body: 'x', reason: 'y', outcome: 'SUCCESSFUL',
    );

    expect($cmd->policyAbility())->toBe('amend')->and($cmd->subjectId())->toBe($id);
});
