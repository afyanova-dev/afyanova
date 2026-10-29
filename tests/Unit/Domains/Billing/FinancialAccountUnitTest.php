<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Command\CloseFinancialAccount;
use App\Domains\Billing\Application\Command\OpenFinancialAccount;
use App\Domains\Billing\Contracts\Event\FinancialAccountClosed;
use App\Domains\Billing\Contracts\Event\FinancialAccountOpened;
use App\Domains\Billing\Domain\Exception\IllegalFinancialAccountTransition;
use App\Domains\Billing\Domain\Model\FinancialAccount;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\Service\FinancialAccountLifecycle;
use App\Domains\Billing\Domain\ValueObject\FinancialAccountReason;
use App\Domains\Billing\Domain\ValueObject\FinancialAccountStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

test('OpenFinancialAccount validates ids + currency; CloseFinancialAccount validates the reason SET; audit targets the account', function () {
    $open = new OpenFinancialAccount(
        financialAccountId: $a = (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        currency: 'tzs',
    );

    expect($open->policyAbility())->toBe('open')
        ->and($open->subjectId())->toBeNull()
        ->and($open->currency)->toBe('TZS')
        ->and($open->auditEntry()->action)->toBe('billing.financial_account_opened')
        ->and($open->auditEntry()->targetId)->toBe($a);

    expect(fn () => new OpenFinancialAccount(
        financialAccountId: (string) Str::uuid7(), actorId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(), facilityId: (string) Str::uuid7(), currency: 'USD',
    ))->toThrow(InvalidArgumentException::class);

    $close = new CloseFinancialAccount(
        financialAccountId: $a, actorId: (string) Str::uuid7(), reasonCode: 'duplicate_account',
    );
    expect($close->policyAbility())->toBe('close')
        ->and($close->subjectId())->toBe($a)
        ->and($close->reasonCode)->toBe('DUPLICATE_ACCOUNT')
        ->and($close->auditEntry()->reason)->toBe('DUPLICATE_ACCOUNT');

    expect(fn () => new CloseFinancialAccount(
        financialAccountId: (string) Str::uuid7(), actorId: (string) Str::uuid7(), reasonCode: 'NONSENSE',
    ))->toThrow(InvalidArgumentException::class, 'Invalid financial-account close reason');
});

test('FinancialAccount::open() is OPEN + FinancialAccountOpened (PHI-free); close() → CLOSED + FinancialAccountClosed', function () {
    $now = CarbonImmutable::now();
    $account = FinancialAccount::open(
        id: $id = (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        accountNumber: 'FA-00000001',
        currency: 'TZS',
        openedByUserId: $u = (string) Str::uuid7(),
        occurredAt: $now,
        recordedAt: $now,
    );

    expect($account->status)->toBe(FinancialAccountStatus::OPEN);

    $opened = $account->releaseEvents();
    expect($opened)->toHaveCount(1)
        ->and($opened[0])->toBeInstanceOf(FinancialAccountOpened::class)
        ->and($opened[0]->accountNumber)->toBe('FA-00000001')
        ->and($opened[0]->openedByUserId)->toBe($u);

    $account->close(closedByUserId: $u, reasonCode: 'ADMIN_CLOSURE', occurredAt: $now);
    expect($account->status)->toBe(FinancialAccountStatus::CLOSED)
        ->and((string) $account->close_reason_code)->toBe('ADMIN_CLOSURE');

    $closed = $account->releaseEvents();
    expect($closed)->toHaveCount(1)
        ->and($closed[0])->toBeInstanceOf(FinancialAccountClosed::class)
        ->and($closed[0]->closeReasonCode)->toBe('ADMIN_CLOSURE');
});

test('FinancialAccountLifecycle + Money helpers', function () {
    $lifecycle = new FinancialAccountLifecycle;

    $lifecycle->assertCanClose(FinancialAccountStatus::OPEN);
    $lifecycle->assertCanClose(FinancialAccountStatus::ACTIVE);
    $lifecycle->assertCanClose(FinancialAccountStatus::SETTLED);
    expect(fn () => $lifecycle->assertCanClose(FinancialAccountStatus::CLOSED))
        ->toThrow(IllegalFinancialAccountTransition::class);

    expect(FinancialAccountReason::assertClose('  patient_deceased '))->toBe('PATIENT_DECEASED');
    expect(Money::isZero('0'))->toBeTrue()
        ->and(Money::isZero('0.00'))->toBeTrue()
        ->and(Money::isZero('0.01'))->toBeFalse()
        ->and(Money::subtract('120.00', '45.50'))->toBe('74.50');
});
