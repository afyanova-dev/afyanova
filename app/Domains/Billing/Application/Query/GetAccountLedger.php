<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\FinancialAccount;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read a Financial Account's posted ledger rows, newest first
 * (20-billing-and-payments-domain.md §86, §295; ADR-187 D-E). `view` gates on
 * `billing.access`. `$limit` is capped at 500 (cursor pagination is deferred to
 * Bill.3+ when the ledger has volume).
 */
#[ProtectedQuery(ability: 'view', subjectType: FinancialAccount::class)]
final readonly class GetAccountLedger
{
    public int $limit;

    public function __construct(public string $financialAccountId, int $limit = 100)
    {
        if (! Str::isUuid($financialAccountId)) {
            throw new InvalidArgumentException('GetAccountLedger::$financialAccountId must be a UUID (IC §3).');
        }

        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('GetAccountLedger::$limit must be between 1 and 500 (ADR-187 D-E).');
        }

        $this->limit = $limit;
    }
}
