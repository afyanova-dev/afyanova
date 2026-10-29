<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

/**
 * The shared status set for a high-risk financial-correction request — a
 * write-off (§78) or an adjustment (§81) (20-billing-and-payments-domain.md
 * §82 / §139; ADR-191 G-3):
 *
 *   REQUESTED — raised, awaiting a distinct approver (dual-control path).
 *   APPLIED   — approved AND the ledger entry posted (one atomic step — there
 *               is no resting APPROVED state; `approved_by` / `approved_at`
 *               record who / when). Below the per-tenant threshold the request
 *               is created APPLIED with `approved_by = requested_by`.
 *   REVERSED  — the applied correction was fully undone (§72) — a compensating
 *               ledger DEBIT; itself an SoD-approved action.
 *   REJECTED  — an approver declined the request; no ledger entry. Terminal.
 *
 * Backend-only string-constant holder.
 */
final class FinancialControlStatus
{
    public const REQUESTED = 'REQUESTED';

    public const APPLIED = 'APPLIED';

    public const REVERSED = 'REVERSED';

    public const REJECTED = 'REJECTED';

    /** @var list<string> */
    public const ALL = [self::REQUESTED, self::APPLIED, self::REVERSED, self::REJECTED];
}
