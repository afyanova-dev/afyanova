<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Dto;

/**
 * A Financial Account's derived balance (20-billing-and-payments-domain.md §83,
 * §84, §295; ADR-187 D-E; ADR-191 G-1).
 *
 *   outstanding = chargedTotal − settledTotal  ≡  Σ(DEBIT) − Σ(CREDIT)
 *
 * Computed from `bill_account_ledger_entries` on every read — never stored
 * (§84). Every total is broken down BY `entry_type` (ADR-191 G-1), NOT by
 * `direction` alone — so a reversed charge is netted out of `chargedTotal` and
 * never mistaken for settlement:
 *
 *   chargedTotal    = Σ(CHARGE DEBIT)     − Σ(CHARGE CREDIT)      net charges after reversals
 *   paidTotal       = Σ(PAYMENT CREDIT)   − Σ(PAYMENT DEBIT)      net money received (Bill.5)
 *   writeOffTotal   = Σ(WRITE_OFF CREDIT) − Σ(WRITE_OFF DEBIT)    net written off (Bill.7)
 *   adjustmentTotal = Σ(ADJUSTMENT CREDIT)− Σ(ADJUSTMENT DEBIT)   net adjustment credit (Bill.7)
 *   settledTotal    = paidTotal + writeOffTotal + adjustmentTotal
 *
 * `settledTotal` was `Σ(all CREDIT)` in Bill.1–4 — REDEFINED here (ADR-191 G-1)
 * to exclude charge reversals, which fixes the recorded Bill.3 limitation. It
 * stays `"0.00"` for an account with only (possibly reversed) charges. All
 * money fields are fixed-scale-2 numeric strings; `paidTotal` etc. are
 * "0.00" until their increment ships. NOT `#[TypeScript]`.
 */
final readonly class OutstandingBalanceSummary
{
    public function __construct(
        public string $financialAccountId,
        public string $currency,
        public string $chargedTotal,
        public string $paidTotal,
        public string $writeOffTotal,
        public string $adjustmentTotal,
        public string $settledTotal,
        public string $outstanding,
        public int $entryCount,
    ) {}
}
