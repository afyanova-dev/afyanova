<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

/**
 * System-only command to expire lapsed waitlist offers (22-scheduling-and-appointments-domain.md §27).
 */
final readonly class ExpireLapsedWaitlistOffers
{
    public function __construct(
        public ?string $asOf = null,
    ) {}
}
