<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * FollowUp status transition was illegal (26-care-coordination-domain.md §8;
 * ADR-154 D-C). Maps to HTTP 409.
 */
final class IllegalFollowUpTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on follow-up currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_follow_up_transition';
    }
}
