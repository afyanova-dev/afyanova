<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateRadiologyReport` named a study that is not in a state where a report
 * can be written (15-radiology-domain.md §161, §165; ADR-167 D-D). Rad.3a
 * requires the study to be COMPLETED (reporting on an ABORTED study is a later
 * refinement). Maps to HTTP 409.
 */
final class StudyNotReportable extends DomainException
{
    public static function status(string $studyId, string $status): self
    {
        return new self(sprintf(
            'Imaging study [%s] is [%s] — a report can only be created for a COMPLETED study.',
            $studyId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'study_not_reportable';
    }
}
