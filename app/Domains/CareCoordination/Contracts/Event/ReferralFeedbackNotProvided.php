<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Event;

/**
 * A referral's feedback SLA elapsed with nothing received
 * (26-care-coordination-domain.md §16 Invariant 7.7; ADR-151 D-J). The
 * referral itself is unaffected — it may already be COMPLETED. PHI-safe:
 * identifiers only.
 */
final readonly class ReferralFeedbackNotProvided
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $referralId,
        public string $feedbackId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
