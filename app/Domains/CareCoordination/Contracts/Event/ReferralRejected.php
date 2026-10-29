<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Event;

/**
 * A referral was rejected by the target (26-care-coordination-domain.md
 * §11; ADR-151 D-J). The reason is row/audit-only (ADR-136 deny-list), never
 * carried here. PHI-safe: identifiers only.
 */
final readonly class ReferralRejected
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
