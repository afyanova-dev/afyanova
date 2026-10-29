<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Event;

/**
 * A referral's episode of care was completed by the target
 * (26-care-coordination-domain.md §11; ADR-151 D-J). PHI-safe: identifiers
 * only.
 */
final readonly class ReferralCompleted
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
