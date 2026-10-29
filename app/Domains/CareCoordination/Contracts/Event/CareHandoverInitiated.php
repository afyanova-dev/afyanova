<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Event;

/**
 * A transfer-of-care handover was initiated alongside a referral
 * (26-care-coordination-domain.md §11; ADR-151 D-J — `implies_handover`
 * referrals only). PHI-safe: identifiers only.
 */
final readonly class CareHandoverInitiated
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
