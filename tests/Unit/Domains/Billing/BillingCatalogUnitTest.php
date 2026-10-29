<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Command\AddBillingService;
use App\Domains\Billing\Application\Command\SetTariff;
use App\Domains\Billing\Domain\Model\BillingService;
use App\Domains\Billing\Domain\Model\Tariff;
use App\Domains\Billing\Domain\Service\TariffResolver;
use App\Domains\Billing\Domain\TariffCandidate;
use App\Domains\Billing\Domain\ValueObject\TariffStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

test('TariffResolver picks FACILITY > TENANT_DEFAULT > NONE', function () {
    $resolver = new TariffResolver;
    $svc = (string) Str::uuid7();
    $fac = (string) Str::uuid7();

    $facilityCand = new TariffCandidate((string) Str::uuid7(), '8000.00', 'TZS', '2026-01-01');
    $defaultCand = new TariffCandidate((string) Str::uuid7(), '5000.00', 'TZS', '2026-01-01');

    expect($resolver->resolve($svc, $fac, 'ACTIVE', $facilityCand, $defaultCand))
        ->resolvedScope->toBe(TariffStatus::SCOPE_FACILITY)
        ->and($resolver->resolve($svc, $fac, 'ACTIVE', $facilityCand, $defaultCand)->amount)->toBe('8000.00');

    expect($resolver->resolve($svc, $fac, 'ACTIVE', null, $defaultCand))
        ->resolvedScope->toBe(TariffStatus::SCOPE_TENANT_DEFAULT)
        ->and($resolver->resolve($svc, $fac, 'ACTIVE', null, $defaultCand)->amount)->toBe('5000.00');

    $none = $resolver->resolve($svc, $fac, 'INACTIVE', null, null);
    expect($none->resolvedScope)->toBe(TariffStatus::SCOPE_NONE)
        ->and($none->amount)->toBeNull()
        ->and($none->serviceStatus)->toBe('INACTIVE');
});

test('AddBillingService validates the category SET; SetTariff rejects sub-cent / non-positive / non-TZS', function () {
    $ok = new AddBillingService(
        billingServiceId: (string) Str::uuid7(), actorId: (string) Str::uuid7(),
        code: '  consult ', name: '  Consultation ', category: 'consultation',
    );
    expect($ok->code)->toBe('consult')->and($ok->name)->toBe('Consultation')->and($ok->category)->toBe('CONSULTATION')
        ->and($ok->policyAbility())->toBe('manage')->and($ok->subjectId())->toBeNull();

    expect(fn () => new AddBillingService(
        billingServiceId: (string) Str::uuid7(), actorId: (string) Str::uuid7(),
        code: 'x', name: 'x', category: 'NONSENSE',
    ))->toThrow(InvalidArgumentException::class, 'Invalid billing-service category');

    $base = fn (string $amount, string $currency = 'TZS') => new SetTariff(
        tariffId: (string) Str::uuid7(), actorId: (string) Str::uuid7(), billingServiceId: (string) Str::uuid7(),
        facilityId: null, amount: $amount, effectiveFrom: '2026-01-01', currency: $currency,
    );

    expect($base('5000'))->amount->toBe('5000.00');
    expect($base('5000.5'))->amount->toBe('5000.50');
    expect(fn () => $base('12.345'))->toThrow(InvalidArgumentException::class, 'sub-cent');
    expect(fn () => $base('0'))->toThrow(InvalidArgumentException::class, 'positive');
    expect(fn () => $base('-1'))->toThrow(InvalidArgumentException::class, 'positive');
    expect(fn () => $base('5000', 'USD'))->toThrow(InvalidArgumentException::class, 'TZS');
});

test('BillingService + Tariff aggregate transitions', function () {
    $now = CarbonImmutable::now();

    $svc = BillingService::add((string) Str::uuid7(), (string) Str::uuid7(), 'CBC', 'Full blood count', 'LABORATORY', $now, $now);
    expect($svc->status)->toBe(BillingService::STATUS_ACTIVE)->and($svc->isActive())->toBeTrue();
    $svc->rename('CBC panel', 'OTHER');
    expect($svc->name)->toBe('CBC panel')->and($svc->category)->toBe('OTHER');
    $svc->deactivate();
    expect($svc->isActive())->toBeFalse();
    $svc->activate();
    expect($svc->isActive())->toBeTrue();

    $t = Tariff::open((string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7(),
        '5000.00', 'TZS', CarbonImmutable::parse('2026-01-01'), $now, $now);
    expect($t->status)->toBe(Tariff::STATUS_CURRENT)->and($t->isOpen())->toBeTrue()->and($t->effective_to)->toBeNull()
        ->and($t->releaseEvents())->toHaveCount(0); // no events — Bill.2 is configuration

    $t->supersede(CarbonImmutable::parse('2026-06-01'));
    expect($t->status)->toBe(Tariff::STATUS_SUPERSEDED)
        ->and($t->effective_to->toDateString())->toBe('2026-06-01')
        ->and($t->isOpen())->toBeFalse();
});
