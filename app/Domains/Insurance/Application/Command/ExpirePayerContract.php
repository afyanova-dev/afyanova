<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * System-only entry point for the `insurance:expire-contracts` hygiene sweep
 * (21-insurance-and-payer-domain.md §49 / §113; ADR-203). Flips an ACTIVE
 * contract past its `effective_to` to `EXPIRED`.
 *
 * Not exposed to a human actor — no permission key, plain command (the
 * `ExpireContract` and `ExpireAuthorization` precedent).
 */
final readonly class ExpirePayerContract
{
    public function __construct(
        public string $contractId,
    ) {
        if (! Str::isUuid($contractId)) {
            throw new InvalidArgumentException('ExpirePayerContract::$contractId must be a UUID (IC §3).');
        }
    }
}
