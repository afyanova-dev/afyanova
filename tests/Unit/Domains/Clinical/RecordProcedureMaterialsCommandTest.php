<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\RecordProcedureMaterials;
use Illuminate\Support\Str;

/*
 | PHASE 6 (CP.1) unit gate — RecordProcedureMaterials command-level
 | validation (ADR-146 D-N). No DB.
 */

test('rejects an empty materials list', function () {
    expect(fn () => new RecordProcedureMaterials(procedureId: (string) Str::uuid7(), actorId: (string) Str::uuid7(), materials: []))
        ->toThrow(InvalidArgumentException::class, 'must not be empty');
});

test('rejects a line with a blank materialRef', function () {
    expect(fn () => new RecordProcedureMaterials(
        procedureId: (string) Str::uuid7(), actorId: (string) Str::uuid7(),
        materials: [['materialRef' => '  ', 'quantity' => 1.0]],
    ))->toThrow(InvalidArgumentException::class, 'materialRef');
});

test('rejects a line with quantity <= 0', function () {
    expect(fn () => new RecordProcedureMaterials(
        procedureId: (string) Str::uuid7(), actorId: (string) Str::uuid7(),
        materials: [['materialRef' => 'GAUZE', 'quantity' => 0]],
    ))->toThrow(InvalidArgumentException::class, 'quantity > 0');
});

test('accepts a valid multi-line list', function () {
    $cmd = new RecordProcedureMaterials(
        procedureId: (string) Str::uuid7(), actorId: (string) Str::uuid7(),
        materials: [
            ['materialRef' => 'GAUZE', 'quantity' => 2],
            ['materialRef' => 'BANDAGE', 'quantity' => 1, 'unit' => 'roll', 'batchRef' => 'B1'],
        ],
    );

    expect($cmd->materials)->toHaveCount(2)
        ->and($cmd->policyAbility())->toBe('recordMaterials')
        ->and($cmd->subjectId())->toBe($cmd->procedureId);
});
