<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain;

use App\Domains\Scheduling\Domain\Model\WaitlistEntry;
use App\Shared\Domain\Repository;
use Carbon\CarbonImmutable;

/**
 * Write-side repository port for WaitlistEntry aggregate root.
 */
interface WaitlistEntryRepository extends Repository
{
    public function getById(string $id): WaitlistEntry;

    public function getByIdForUpdate(string $id): WaitlistEntry;

    public function existsByNumber(string $waitlistNumber): bool;

    /**
     * Retrieve offered waitlist entries whose offered_until has lapsed.
     *
     * @return list<WaitlistEntry>
     */
    public function getLapsedOffers(CarbonImmutable $asOf): array;
}
