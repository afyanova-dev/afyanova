<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\Exception\FinancialAccountNotSettled;
use App\Domains\Billing\Domain\FinancialAccountReader;
use App\Domains\Billing\Domain\FinancialAccountRepository;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\Service\FinancialAccountLifecycle;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `CloseFinancialAccount` (ADR-187 D-D). `getByIdForUpdate` →
 * `assertCanClose` (OPEN | ACTIVE | SETTLED) → the outstanding balance must be
 * zero (`FinancialAccountNotSettled`; §84) → `->close()` (records
 * `FinancialAccountClosed`; coded reason) → save.
 */
final class CloseFinancialAccountHandler
{
    public function __construct(
        private readonly FinancialAccountRepository $accounts,
        private readonly FinancialAccountReader $reader,
        private readonly FinancialAccountLifecycle $lifecycle,
    ) {}

    public function __invoke(CloseFinancialAccount $command): void
    {
        $account = $this->accounts->getByIdForUpdate($command->financialAccountId);

        $this->lifecycle->assertCanClose((string) $account->status);

        $balance = $this->reader->outstandingBalance($command->financialAccountId);
        if (! Money::isZero($balance->outstanding)) {
            throw FinancialAccountNotSettled::outstanding($command->financialAccountId, $balance->outstanding);
        }

        $account->close($command->actorId, $command->reasonCode, CarbonImmutable::now());

        $this->accounts->save($account);
    }
}
