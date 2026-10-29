<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Domain\Model\ImagingStudy;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one imaging study (ADR-166 D-G).
 */
#[ProtectedQuery(ability: 'view', subjectType: ImagingStudy::class)]
final readonly class GetImagingStudy
{
    public function __construct(public string $imagingStudyId)
    {
        if (! Str::isUuid($imagingStudyId)) {
            throw new InvalidArgumentException('GetImagingStudy::$imagingStudyId must be a UUID (IC §3).');
        }
    }
}
