<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts;

use App\Domains\Billing\Contracts\Dto\AccountLedgerEntry;
use App\Domains\Billing\Contracts\Dto\FinancialAccountSummary;
use App\Domains\Billing\Contracts\Dto\InvoiceSummary;
use App\Domains\Billing\Contracts\Dto\OutstandingBalanceSummary;

/**
 * The ONLY Billing & Payments surface other modules may import (IC §2.1;
 * ADR-187 D-H). Published at Bill.1 with the minimum a concrete consumer needs;
 * grows per concrete consumer — do not add a method without a caller (IC §26).
 *
 * `financialAccountExists` is an unprotected existence check — no financial data
 * exposed (the `PatientApi::patientExists` / `EncounterApi::encounterExists`
 * precedent). The charge-capture write surface arrives with Bill.3.
 *
 * `getInvoice` is a protected read (IC §6.2; ADR-204) — dispatches `GetInvoice`
 * over the QueryBus; the caller must hold `billing.access`. Returns null if not found.
 */
interface BillingApi
{
    /** Unprotected existence check — true if the account is in the current tenant scope. */
    public function financialAccountExists(string $financialAccountId): bool;

    /**
     * Read one invoice + its lines + coverage split (ADR-204).
     * Protected read — caller needs `billing.access`.
     */
    public function getInvoice(string $invoiceId): ?InvoiceSummary;

    /**
     * Active financial account for a patient at a facility, if any.
     * Protected read — caller needs `billing.access`.
     */
    public function activeAccountForPatientFacility(string $patientId, string $facilityId): ?FinancialAccountSummary;

    /**
     * Recent ledger entries for an account, newest first (bounded by $limit).
     * Protected read — caller needs `billing.access`.
     *
     * @return list<AccountLedgerEntry>
     */
    public function accountLedger(string $financialAccountId, int $limit = 25): array;

    /**
     * Outstanding balance roll-up for an account.
     * Protected read — caller needs `billing.access`.
     */
    public function accountOutstanding(string $financialAccountId): OutstandingBalanceSummary;

    /**
     * Minimal operational check used before pharmacy hands medication to a
     * recipient. True only when the dispensing charge is settled or has
     * confirmed insurance coverage with its patient responsibility settled.
     * No amount or other financial details are returned.
     */
    public function dispensingClearedForHandover(string $dispensingId): bool;
}
