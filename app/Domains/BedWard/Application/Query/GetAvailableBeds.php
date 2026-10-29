<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Query;

use App\Domains\BedWard\Domain\Model\Bed;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Find available beds filtered by facility, ward type, bed class, and features
 * (24-bed-and-ward-management-domain.md §10; ADR-148 D-I).
 */
#[ProtectedQuery(ability: 'view', subjectType: Bed::class)]
final readonly class GetAvailableBeds
{
    /**
     * @param  list<string>  $features
     */
    public function __construct(
        public string $facilityId,
        public ?string $wardType = null,
        public ?string $bedClass = null,
        public array $features = [],
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetAvailableBeds::$facilityId must be a UUID (IC §3).');
        }
    }
}
