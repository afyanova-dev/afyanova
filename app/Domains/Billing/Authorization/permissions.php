<?php

declare(strict_types=1);

/**
 * Billing & Payments permission keys (20-billing-and-payments-domain.md §164,
 * §165, §276, §277; ADR-187 D-G).
 *
 * A NEW `billing` permission segment. Canonical 3-segment
 * `{domain}.{resource}.{action}` per IC §3 / §12 + ADR-122. Aggregated into
 * PermissionRegistry by `BillingServiceProvider::boot()`.
 *
 * Keys:
 *   - billing.access            — read the module (financial accounts + ledger +
 *                                 outstanding balance).
 *   - billing.accounts.manage   — open / close a Financial Account
 *                                 (`OpenFinancialAccount` / `CloseFinancialAccount`).
 *                                 The `FinancialAccountPolicy` per-facility
 *                                 refinement scopes it to the account's facility.
 *                                 (Bill.1 — ADR-187)
 *   - billing.catalog.manage    — add / update / (de)activate a billing service
 *                                 and set / end a base tariff
 *                                 (`AddBillingService` / `UpdateBillingService` /
 *                                 `SetBillingServiceStatus` / `SetTariff` /
 *                                 `EndTariff`); map an `inv_items.id` to a
 *                                 billing service (`MapItemToBillingService` /
 *                                 `DeactivateItemBillingMap`). Tenant-wide — the
 *                                 base tariff + the item map are organization
 *                                 pricing configuration (§22 / §231).
 *                                 (Bill.2 / Bill.3a — ADR-188 / ADR-189)
 *   - billing.charges.manage     — re-attempt the charge for a PENDING captured
 *                                 billable event (`RetryBillableEvent` — the
 *                                 revenue-leakage worklist, §130 / §131).
 *                                 Tenant-wide. Charge *capture* itself is a
 *                                 system reaction (`CaptureBillableEvent` /
 *                                 `ReverseBillableEvent` from the Pharmacy
 *                                 listeners) — no key. (Bill.3b — ADR-189)
 *   - billing.invoices.manage    — create / add-line / remove-line / issue /
 *                                 void an Invoice (`CreateInvoice` /
 *                                 `AddInvoiceLine` / `RemoveInvoiceLine` /
 *                                 `IssueInvoice` / `VoidInvoice`). Tenant-wide.
 *                                 (Bill.4 — ADR-190)
 *   - billing.payments.manage    — record a payment received against a
 *                                 Financial Account (`RecordPayment`).
 *                                 Tenant-wide (§150 / §151 — a facility may
 *                                 collect for another facility's account).
 *                                 (Bill.5b — ADR-191 B5-C)
 *   - billing.payments.reverse   — reverse a recorded payment (`ReversePayment`)
 *                                 — a DISTINCT key from `.manage` (§164 / §165
 *                                 record-vs-reverse separation-of-duties).
 *                                 Tenant-wide. (Bill.5b — ADR-191 B5-C)
 *   - billing.cash_sessions.manage    — open / close a cashier's till session
 *                                 (`OpenCashSession` / `CloseCashSession`).
 *                                 Tenant-wide (the cashier). (Bill.6 — ADR-191 C6)
 *   - billing.cash_sessions.reconcile — reconcile a closed session + approve a
 *                                 non-zero variance (`ReconcileCashSession`) —
 *                                 a DISTINCT key from `.manage` (§157 / §165:
 *                                 the supervisor). Tenant-wide. (Bill.6 — ADR-191 C6-C)
 *   - billing.writeoffs.request   — raise a write-off (`RequestWriteOff`).
 *                                 Below the per-tenant dual-control threshold
 *                                 this also applies it. Tenant-wide.
 *                                 (Bill.7a — ADR-191 G-3 / D7-A)
 *   - billing.writeoffs.approve   — approve / reject / reverse a write-off
 *                                 (`ApproveWriteOff` / `RejectWriteOff` /
 *                                 `ReverseWriteOff`) — a DISTINCT key from
 *                                 `.request` (§164 / §165 request-vs-approve
 *                                 separation-of-duties; the approver may not be
 *                                 the requester). Tenant-wide. (Bill.7a — ADR-191 G-3)
 *   - billing.adjustments.request — raise a post-charge account-level adjustment
 *                                 (`RequestAdjustment`). Below the per-tenant
 *                                 threshold this also applies it. Tenant-wide.
 *                                 (Bill.7b — ADR-191 G-3 / D7-D)
 *   - billing.adjustments.approve — approve / reject / reverse an adjustment
 *                                 (`ApproveAdjustment` / `RejectAdjustment` /
 *                                 `ReverseAdjustment`) — a DISTINCT key from
 *                                 `.request` (§164 / §165). Tenant-wide.
 *                                 (Bill.7b — ADR-191 G-3)
 *
 * The per-tenant thresholds are CONSOLE-authored (`bill_financial_control_settings`
 * — `billing:configure-financial-control`), NO permission key (ADR-191 G-3a).
 * Refund / deposit keys land with the Insurance & Payer / deposit increments.
 *
 * @return list<string>
 */
return [
    'billing.access',
    'billing.accounts.manage',
    'billing.catalog.manage',
    'billing.charges.manage',
    'billing.invoices.manage',
    'billing.payments.manage',
    'billing.payments.reverse',
    'billing.cash_sessions.manage',
    'billing.cash_sessions.reconcile',
    'billing.writeoffs.request',
    'billing.writeoffs.approve',
    'billing.adjustments.request',
    'billing.adjustments.approve',
];
