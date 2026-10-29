<?php

declare(strict_types=1);

use App\Domains\Procurement\Domain\Exception\IllegalContractTransition;
use App\Domains\Procurement\Domain\Model\Contract;
use App\Domains\Procurement\Domain\Service\ContractLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

$l = new ContractLifecycle;

test('activate only from DRAFT', function () use ($l) {
    $l->assertCanActivate(Contract::STATUS_DRAFT);
    foreach ([Contract::STATUS_ACTIVE, Contract::STATUS_TERMINATED] as $s) {
        expect(fn () => $l->assertCanActivate($s))->toThrow(IllegalContractTransition::class);
    }
});

test('terminate from DRAFT or ACTIVE, never from TERMINATED or SUPERSEDED', function () use ($l) {
    $l->assertCanTerminate(Contract::STATUS_DRAFT);
    $l->assertCanTerminate(Contract::STATUS_ACTIVE);
    foreach ([Contract::STATUS_TERMINATED, Contract::STATUS_SUPERSEDED] as $s) {
        expect(fn () => $l->assertCanTerminate($s))->toThrow(IllegalContractTransition::class);
    }
});

test('amend / supersede only from ACTIVE (ADR-178 D-D)', function () use ($l) {
    $l->assertCanAmend(Contract::STATUS_ACTIVE);
    $l->assertCanSupersede(Contract::STATUS_ACTIVE);
    foreach ([Contract::STATUS_DRAFT, Contract::STATUS_TERMINATED, Contract::STATUS_SUPERSEDED] as $s) {
        expect(fn () => $l->assertCanAmend($s))->toThrow(IllegalContractTransition::class);
        expect(fn () => $l->assertCanSupersede($s))->toThrow(IllegalContractTransition::class);
    }
});

test('isUsable() is true only when ACTIVE and not past expiry_date (check-at-use, Inv 32)', function () {
    $future = Contract::create(
        id: (string) Str::uuid7(), tenantId: (string) Str::uuid7(), contractNumber: 'CON-00000001',
        supplierId: (string) Str::uuid7(), title: 'x', createdByUserId: (string) Str::uuid7(),
        effectiveDate: CarbonImmutable::now()->subMonth()->toDateString(),
        expiryDate: CarbonImmutable::now()->addYear()->toDateString(),
        reason: null, terms: null, currency: 'TZS',
    );

    expect($future->isUsable())->toBeFalse(); // DRAFT

    $future->markActive((string) Str::uuid7());
    expect($future->isUsable())->toBeTrue();

    $expired = Contract::create(
        id: (string) Str::uuid7(), tenantId: (string) Str::uuid7(), contractNumber: 'CON-00000002',
        supplierId: (string) Str::uuid7(), title: 'x', createdByUserId: (string) Str::uuid7(),
        effectiveDate: CarbonImmutable::now()->subYears(2)->toDateString(),
        expiryDate: CarbonImmutable::now()->subDay()->toDateString(),
        reason: null, terms: null, currency: 'TZS',
    );
    $expired->markActive((string) Str::uuid7());
    expect($expired->isUsable())->toBeFalse(); // past expiry

    $future->markTerminated((string) Str::uuid7(), 'supplier folded');
    expect($future->isUsable())->toBeFalse(); // TERMINATED
});
