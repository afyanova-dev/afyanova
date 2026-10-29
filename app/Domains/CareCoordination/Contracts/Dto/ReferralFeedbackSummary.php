<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Dto;

/**
 * Minimal summary read of one referral-feedback row (ADR-151 D-I).
 */
final readonly class ReferralFeedbackSummary
{
    public function __construct(
        public string $feedbackId,
        public string $facilityId,
        public string $referralId,
        public int $sequence,
        public ?string $kind,
        public ?string $receivedAt,
        public ?string $receivedVia,
        public ?string $summary,
        public ?string $outcome,
        public string $status,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
