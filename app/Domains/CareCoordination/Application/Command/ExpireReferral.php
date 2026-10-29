<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * System-only expiry entry point for the optional, deferred SLA-sweep job
 * (26-care-coordination-domain.md §16; ADR-151 D-F/D-J). Not exposed to a
 * human actor — no permission key, plain command (the `ExpireBedReservation`
 * / event-originated system-command precedent, IC §7.3), never dispatched
 * from an HTTP path.
 *
 * Found missing during the ADR-151 implementation audit: D-F names an
 * EXPIRED transition and D-J names `ReferralExpired` as one of CC.1's 12
 * events, but no command existed anywhere to reach either — `Referral::
 * expire()`, `ReferralLifecycle::assertCanExpire()`, and the `ReferralExpired`
 * event class were all unreachable dead code until this command was added.
 * CC.1 is 13 commands, not 12.
 */
final readonly class ExpireReferral
{
    public function __construct(
        public string $referralId,
    ) {
        if (! Str::isUuid($referralId)) {
            throw new InvalidArgumentException('ExpireReferral::$referralId must be a UUID (IC §3).');
        }
    }
}
