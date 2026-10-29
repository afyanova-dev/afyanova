<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A radiology critical-finding status transition was illegal
 * (15-radiology-domain.md §167; ADR-169 D-C). Maps to HTTP 409.
 */
final class IllegalRadiologyCriticalFindingTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on radiology critical finding currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_radiology_critical_finding_transition';
    }
}
