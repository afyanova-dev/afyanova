<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Contracts\Dto\CashSessionSummary;

/**
 * Read-side port for cash sessions (IC §6.2; ADR-191 C6 / G-4). While a session
 * is LIVE the `expectedCash` / `variance` are computed from the current CASH
 * payments; once CLOSED they are the frozen row values.
 */
interface CashSessionReader
{
    /** Throws `App\Domains\Billing\Domain\Exception\CashSessionNotFound` if absent (RLS). */
    public function summary(string $cashSessionId): CashSessionSummary;

    /**
     * The cash sessions at a facility, newest first — the supervisor worklist.
     *
     * @param  string|null  $status  optional exact-status filter
     * @return list<CashSessionSummary>
     */
    public function forFacility(string $facilityId, ?string $status): array;
}
