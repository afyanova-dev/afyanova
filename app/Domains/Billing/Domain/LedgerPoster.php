<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

/**
 * The write port for `bill_account_ledger_entries` (ADR-187 D-C — "Bill.1 ships
 * the table + reader; no writer — Bill.3 adds the first"; ADR-189 D-I).
 *
 * A ledger entry is a plain row (NOT an aggregate — §290), written inside the
 * command transaction that creates the originating fact. It emits no event
 * itself (the `ChargeCreated` / `ChargeReversed` events carry the fact).
 * `entry_type` stays within the ADR-187 7-value set ({@see LedgerEntryType}) —
 * a charge is `CHARGE` / `DEBIT`; a reversal is `CHARGE` / `CREDIT` +
 * `source_type 'CHARGE_REVERSAL'` (no new `entry_type`); the full Bill.5–7
 * posting matrix is ADR-191 G-1.
 *
 * G-2 (ADR-191): one fact posts AT MOST one ledger row — `post` is idempotent
 * on `(tenant_id, source_type, source_id)` (a pre-check + a DB `unique` index).
 * A re-run / listener-job retry is a silent no-op.
 *
 * Implementation: `Infrastructure/Ledger/EloquentLedgerPoster`.
 */
interface LedgerPoster
{
    /**
     * @param  numeric-string  $amount  scale-2, >= 0
     */
    public function post(
        string $financialAccountId,
        string $facilityId,
        string $entryType,
        string $direction,
        string $amount,
        string $currency,
        string $sourceType,
        string $sourceId,
    ): void;
}
