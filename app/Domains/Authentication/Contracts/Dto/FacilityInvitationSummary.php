<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Contracts\Dto;

final readonly class FacilityInvitationSummary
{
    public function __construct(
        public string $invitationId,
        public string $userId,
        public string $facilityId,
        public ?string $roleKey,
        public ?string $roleKeys,
        public string $purpose,
        public string $expiresAt,
        public ?string $consumedAt,
        public string $name,
        public string $email,
        public ?string $emailVerifiedAt,
        public string $status,
        public string $invitationStatus,
        public ?string $sentAt,
        public string $deliveryStatus,
        public ?string $supersededAt,
        public ?string $revokedAt,
        public ?string $revocationReason,
        public int $resendCount,
        public ?string $lastSentByName,
        public bool $isExpired,
        public bool $canResend,
        public bool $canRevoke,
    ) {}
}
