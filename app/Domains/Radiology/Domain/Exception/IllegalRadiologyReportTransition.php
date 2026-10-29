<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A radiology-report status transition was illegal (15-radiology-domain.md
 * §103, §161, §164; ADR-167 D-C / ADR-168 D-C). Maps to HTTP 409.
 */
final class IllegalRadiologyReportTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on radiology report currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_radiology_report_transition';
    }
}
