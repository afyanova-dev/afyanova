<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * System-only entry point for the `procurement:expire-contracts` hygiene sweep
 * (19-procurement-domain.md §194; ADR-179 D-C). Flips an ACTIVE contract that is
 * past its `expiry_date` to `EXPIRED`.
 *
 * Not exposed to a human actor — no permission key, plain command (the
 * `ExpireBedReservation` / `ExpireReferral` / IC §7.3 system-command precedent),
 * never dispatched from an HTTP path. No `idempotencyKey` — a re-dispatch is a
 * handler no-op. Inv 32 enforcement remains check-at-use
 * (`Contract::isUsable()`); this only makes the stored `status` honest.
 */
final readonly class ExpireContract
{
    public function __construct(
        public string $contractId,
    ) {
        if (! Str::isUuid($contractId)) {
            throw new InvalidArgumentException('ExpireContract::$contractId must be a UUID (IC §3).');
        }
    }
}
