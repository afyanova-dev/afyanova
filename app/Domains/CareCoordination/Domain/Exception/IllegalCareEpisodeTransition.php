<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * CareEpisode status transition was illegal (26-care-coordination-domain.md
 * §8; ADR-153 D-C). Maps to HTTP 409.
 */
final class IllegalCareEpisodeTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on care episode currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_care_episode_transition';
    }
}
