<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Event;

/**
 * Feedback content was recorded against a referral (26-care-coordination-domain.md
 * §11; ADR-151 D-J). `summary`/`outcome` are row-only (ADR-136 deny-list),
 * never carried here. PHI-safe: identifiers and coded `kind` only.
 */
final readonly class ReferralFeedbackRecorded
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $referralId,
        public string $feedbackId,
        public ?string $kind,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
