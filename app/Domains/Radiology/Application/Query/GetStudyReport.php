<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read the radiology report for a study (15-radiology-domain.md §128, §188;
 * ADR-167 D-F).
 */
#[ProtectedQuery(ability: 'view', subjectType: RadiologyReport::class)]
final readonly class GetStudyReport
{
    public function __construct(public string $studyId)
    {
        if (! Str::isUuid($studyId)) {
            throw new InvalidArgumentException('GetStudyReport::$studyId must be a UUID (IC §3).');
        }
    }
}
