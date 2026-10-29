<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain;

use App\Domains\BedWard\Contracts\Dto\BedSummary;

/**
 * Read-side port for Bed queries (IC §6, §9).
 */
interface BedReader
{
    public function summary(string $bedId): BedSummary;

    /**
     * @param  list<string>  $features
     * @return list<BedSummary>
     */
    public function availableBeds(
        string $facilityId,
        ?string $wardType = null,
        ?string $bedClass = null,
        array $features = [],
    ): array;
}
