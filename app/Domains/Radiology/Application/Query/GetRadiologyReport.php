<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one radiology report (ADR-167 D-F).
 */
#[ProtectedQuery(ability: 'view', subjectType: RadiologyReport::class)]
final readonly class GetRadiologyReport
{
    public function __construct(public string $reportId)
    {
        if (! Str::isUuid($reportId)) {
            throw new InvalidArgumentException('GetRadiologyReport::$reportId must be a UUID (IC §3).');
        }
    }
}
