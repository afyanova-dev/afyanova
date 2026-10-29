<?php

declare(strict_types=1);

use App\Domains\Pharmacy\Application\Command\Dispense;
use Illuminate\Support\Str;

test('Dispense validates ids, a positive quantity and a non-future occurredAt', function () {
    $cmd = new Dispense(
        dispensingId: $id = (string) Str::uuid7(),
        actorId: $actor = (string) Str::uuid7(),
        pharmacyOrderId: $order = (string) Str::uuid7(),
        itemId: (string) Str::uuid7(),
        locationId: (string) Str::uuid7(),
        quantity: '12.5',
    );

    expect($cmd->policyAbility())->toBe('dispense')
        ->and($cmd->subjectId())->toBe($order);

    $audit = $cmd->auditEntry();
    expect($audit->action)->toBe('pharmacy.medication_dispensed')
        ->and($audit->targetType)->toBe('dispensing')
        ->and($audit->targetId)->toBe($id)
        ->and($audit->actorId)->toBe($actor);

    expect(fn () => new Dispense(
        dispensingId: (string) Str::uuid7(), actorId: (string) Str::uuid7(),
        pharmacyOrderId: (string) Str::uuid7(), itemId: (string) Str::uuid7(),
        locationId: (string) Str::uuid7(), quantity: '0',
    ))->toThrow(InvalidArgumentException::class, 'positive');

    expect(fn () => new Dispense(
        dispensingId: 'not-a-uuid', actorId: (string) Str::uuid7(),
        pharmacyOrderId: (string) Str::uuid7(), itemId: (string) Str::uuid7(),
        locationId: (string) Str::uuid7(), quantity: '1',
    ))->toThrow(InvalidArgumentException::class, 'UUID');

    expect(fn () => new Dispense(
        dispensingId: (string) Str::uuid7(), actorId: (string) Str::uuid7(),
        pharmacyOrderId: (string) Str::uuid7(), itemId: (string) Str::uuid7(),
        locationId: (string) Str::uuid7(), quantity: '1',
        occurredAt: now()->addHour()->toIso8601String(),
    ))->toThrow(InvalidArgumentException::class, 'future');
});
