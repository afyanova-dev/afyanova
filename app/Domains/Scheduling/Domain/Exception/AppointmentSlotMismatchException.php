<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Exception;

use DomainException;

final class AppointmentSlotMismatchException extends DomainException
{
    public static function forResourceMismatch(string $slotId, string $slotResourceId, string $appointmentResourceId): self
    {
        return new self("Slot [{$slotId}] resource [{$slotResourceId}] does not match appointment resource [{$appointmentResourceId}].");
    }

    public static function forTypeMismatch(string $slotId, string $slotTypeId, string $appointmentTypeId): self
    {
        return new self("Slot [{$slotId}] appointment type [{$slotTypeId}] does not match requested appointment type [{$appointmentTypeId}].");
    }
}
