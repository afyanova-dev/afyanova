<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Event;

/**
 * A referral was sent (26-care-coordination-domain.md §11; ADR-151 D-J).
 *
 * PHI-safe: identifiers and coded values only (ADR-136 deny-list —
 * clinical_reason_ref/summary/outcome never carried).
 */
final readonly class ReferralSent
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $referralId,
        public string $targetType,
        public string $targetRef,
        public string $priority,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
