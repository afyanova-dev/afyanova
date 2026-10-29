<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Dto;

/**
 * Minimal summary read of one referral (ADR-151 D-I). A protected read
 * model, not an event payload — the ADR-136 deny-list applies to events/
 * audit, not to a gated query result for an authorized caller.
 */
final readonly class ReferralSummary
{
    public function __construct(
        public string $referralId,
        public string $facilityId,
        public string $patientId,
        public string $direction,
        public bool $impliesHandover,
        public ?string $sourceEncounterId,
        public string $targetType,
        public string $targetRef,
        public ?string $specialtyRequested,
        public string $priority,
        public ?string $clinicalReasonRef,
        public ?string $consentBasisRef,
        public string $status,
        public ?string $targetEncounterId,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
