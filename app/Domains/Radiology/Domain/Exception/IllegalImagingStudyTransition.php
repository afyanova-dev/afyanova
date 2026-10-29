<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * An imaging-study status transition was illegal (15-radiology-domain.md §104,
 * §161; ADR-166 D-D). Maps to HTTP 409.
 */
final class IllegalImagingStudyTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on imaging study currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_imaging_study_transition';
    }
}
