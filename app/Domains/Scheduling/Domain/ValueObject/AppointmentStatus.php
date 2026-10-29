<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\ValueObject;

/**
 * Lifecycle status of an appointment (22-scheduling-and-appointments-domain.md §48, ADR-207).
 */
enum AppointmentStatus: string
{
    case REQUESTED = 'REQUESTED';
    case BOOKED = 'BOOKED';
    case CONFIRMED = 'CONFIRMED';
    case ARRIVED = 'ARRIVED';
    case IN_SERVICE = 'IN_SERVICE';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case RESCHEDULED = 'RESCHEDULED';
    case NO_SHOW = 'NO_SHOW';

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED, self::RESCHEDULED, self::NO_SHOW], true);
    }

    public function canBeConfirmed(): bool
    {
        return in_array($this, [self::REQUESTED, self::BOOKED], true);
    }

    public function canRecordArrival(): bool
    {
        return in_array($this, [self::BOOKED, self::CONFIRMED], true);
    }

    public function canStartService(): bool
    {
        return $this === self::ARRIVED;
    }

    public function canComplete(): bool
    {
        return $this === self::IN_SERVICE;
    }

    public function canReschedule(): bool
    {
        return in_array($this, [self::REQUESTED, self::BOOKED, self::CONFIRMED], true);
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::REQUESTED, self::BOOKED, self::CONFIRMED], true);
    }

    public function canMarkNoShow(): bool
    {
        return in_array($this, [self::BOOKED, self::CONFIRMED], true);
    }
}
