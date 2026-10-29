<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Event;

/**
 * A referral was accepted by the target (26-care-coordination-domain.md
 * §7 Invariant 7.5/7.6, §11; ADR-151 D-J). PHI-safe: identifiers only.
 *
 * NOT the trigger for writing Encounter's REFERRED_FROM relationship
 * (Invariant 7.5's literal wording is corrected by ADR-151 D-G — the real
 * trigger is `LinkReferralEncounter`, since the receiving encounter often
 * doesn't exist yet when this event fires).
 */
final readonly class ReferralAccepted
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $referralId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
