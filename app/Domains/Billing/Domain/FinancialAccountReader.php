<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Contracts\Dto\AccountLedgerEntry;
use App\Domains\Billing\Contracts\Dto\FinancialAccountSummary;
use App\Domains\Billing\Contracts\Dto\OutstandingBalanceSummary;

/**
 * Read-side port for the Financial Account + its ledger (IC §6.2 / §6.3;
 * ADR-187 D-E). Returns DTOs only, never an Eloquent model or a query builder.
 */
interface FinancialAccountReader
{
    /** Throws `App\Shared\Domain\NotFound` if absent from the current tenant scope (RLS). */
    public function summary(string $financialAccountId): FinancialAccountSummary;

    /** True if the account exists in the current tenant scope (the `BillingApi` existence check). */
    public function exists(string $financialAccountId): bool;

    /**
     * The account's posted ledger rows, newest first, capped at `$limit`
     * (ADR-187 D-E — cursor pagination deferred to Bill.3+ when there is volume).
     *
     * @return list<AccountLedgerEntry>
     */
    public function ledgerEntries(string $financialAccountId, int $limit): array;

    /** The derived balance — `Σ(DEBIT) − Σ(CREDIT)` over the ledger (§84). */
    public function outstandingBalance(string $financialAccountId): OutstandingBalanceSummary;

    /**
     * The newest non-CLOSED account for (patient, facility), or null when the
     * patient has no open account at the facility (the cashier lookup path —
     * presentation resolves the account before touching any money writer).
     */
    public function activeForPatientFacility(string $patientId, string $facilityId): ?FinancialAccountSummary;
}
