<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

final readonly class CorrectOccupancyResult
{
    /**
     * @param  list<string>  $changedFields
     */
    public function __construct(
        public string $occupancyId,
        public array $changedFields,
    ) {}
}
