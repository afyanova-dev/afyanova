<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * ReferralFeedback status transition was illegal (26-care-coordination-domain.md
 * §8; ADR-151 D-F). Maps to HTTP 409.
 */
final class IllegalReferralFeedbackTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on referral feedback currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_referral_feedback_transition';
    }
}
