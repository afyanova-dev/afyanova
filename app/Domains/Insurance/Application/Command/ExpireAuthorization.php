<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * System-only entry point for the `insurance:expire-authorizations` hygiene sweep
 * (21-insurance-and-payer-domain.md §58 / §182; ADR-202 D-B). Flips an APPROVED
 * or PARTIALLY_APPROVED authorization past its `valid_to` to `EXPIRED`.
 *
 * Not exposed to a human actor — no permission key, plain command (the
 * `ExpireContract` precedent), never dispatched from an HTTP path. No
 * `idempotencyKey` — a re-dispatch is a handler no-op. Inv 25 enforcement
 * remains check-at-use (`Authorization::isEffectiveOn()`); this only makes the
 * stored `status` honest.
 */
final readonly class ExpireAuthorization
{
    public function __construct(
        public string $authorizationId,
    ) {
        if (! Str::isUuid($authorizationId)) {
            throw new InvalidArgumentException('ExpireAuthorization::$authorizationId must be a UUID (IC §3).');
        }
    }
}
