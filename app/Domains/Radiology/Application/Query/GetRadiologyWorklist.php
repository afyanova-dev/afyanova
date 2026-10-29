<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Domain\Model\ImagingStudy;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The radiology worklist — imaging studies at a facility, optionally filtered
 * by status; defaults to the non-terminal studies (15-radiology-domain.md §74,
 * §76; ADR-166 D-G).
 */
#[ProtectedQuery(ability: 'view', subjectType: ImagingStudy::class)]
final readonly class GetRadiologyWorklist
{
    /** @var list<string>|null */
    public ?array $statuses;

    /**
     * @param  list<string>|null  $statuses
     */
    public function __construct(public string $facilityId, ?array $statuses = null)
    {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetRadiologyWorklist::$facilityId must be a UUID (IC §3).');
        }

        if ($statuses !== null) {
            $normalised = array_values(array_unique(array_map(
                static fn (string $s): string => strtoupper(trim($s)),
                $statuses,
            )));

            foreach ($normalised as $status) {
                if ($status === '') {
                    throw new InvalidArgumentException('GetRadiologyWorklist::$statuses must not contain a blank value.');
                }
            }

            $this->statuses = $normalised;
        } else {
            $this->statuses = null;
        }
    }
}
