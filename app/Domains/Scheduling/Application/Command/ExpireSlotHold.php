<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

/**
 * System-only command to expire a lapsed slot hold (22-scheduling-and-appointments-domain.md §45).
 */
final readonly class ExpireSlotHold
{
    public function __construct(
        public string $slotId,
    ) {}
}
