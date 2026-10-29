<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Exception;

use DomainException;

final class InvalidAppointmentStatusTransitionException extends DomainException
{
    public static function forTransition(string $id, string $fromStatus, string $action): self
    {
        return new self("Cannot perform [{$action}] on appointment [{$id}] in status [{$fromStatus}].");
    }
}
