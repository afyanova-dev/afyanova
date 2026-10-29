<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Application\Dto;

use App\Domains\Authentication\Support\FacilityInvitationStatus;

final readonly class FacilityInvitationForResend
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
        public string $invitationStatus = FacilityInvitationStatus::PENDING,
        public ?string $sentAt = null,
        public string $deliveryStatus = 'PENDING',
        public ?string $supersededAt = null,
        public ?string $revokedAt = null,
        public ?string $revocationReason = null,
        public int $resendCount = 0,
        public ?string $lastSentByName = null,
    ) {}

    public function isExpired(): bool
    {
        return $this->invitationStatus === FacilityInvitationStatus::EXPIRED
            || ($this->invitationStatus === FacilityInvitationStatus::PENDING
                && now()->greaterThanOrEqualTo($this->expiresAt));
    }

    public function canResend(): bool
    {
        return FacilityInvitationStatus::isOpen($this->invitationStatus)
            && $this->consumedAt === null
            && $this->emailVerifiedAt === null
            && $this->status === 'ACTIVE';
    }

    public function canRevoke(): bool
    {
        return $this->canResend();
    }
}
