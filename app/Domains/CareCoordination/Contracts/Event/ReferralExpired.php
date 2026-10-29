<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Event;

/**
 * A referral expired per SLA with no acknowledgement (26-care-coordination-domain.md
 * §16; ADR-151 D-J). PHI-safe: identifiers only.
 */
final readonly class ReferralExpired
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
