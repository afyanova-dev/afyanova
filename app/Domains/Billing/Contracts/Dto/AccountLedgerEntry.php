<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Dto;

/**
 * One posted row of a Financial Account's ledger
 * (20-billing-and-payments-domain.md §86–§88, §295; ADR-187 D-C).
 * Returned by `GetAccountLedger` via `FinancialAccountReader`.
 *
 * NOT `#[TypeScript]`. `amount` is a fixed-scale-2 numeric string; `direction`
 * is `DEBIT` (raises the patient balance) or `CREDIT` (lowers it). In Bill.1 the
 * ledger has no writer, so this DTO is only exercised by Bill.3+.
 */
final readonly class AccountLedgerEntry
{
    public function __construct(
        public string $entryId,
        public string $financialAccountId,
        public string $facilityId,
        public string $entryType,
        public string $direction,
        public string $amount,
        public string $currency,
        public string $sourceType,
        public string $sourceId,
        public ?string $memoCode,
        public string $postedAt,
    ) {}
}
