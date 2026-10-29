<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `ReleaseResult` failed the release safety gate (14-laboratory-domain.md
 * §158, §229; ADR-159 D-E). Maps to HTTP 409.
 */
final class LabResultNotReleasable extends DomainException
{
    public static function notVerified(string $resultId, string $status): self
    {
        return new self(sprintf(
            'Laboratory result [%s] is [%s] — release requires a VERIFIED result (§229; ADR-159 D-E).',
            $resultId,
            $status,
        ));
    }

    public static function onHold(string $resultId, string $reason): self
    {
        return new self(sprintf(
            'Laboratory result [%s] is on release hold [%s] and cannot be released (§159; ADR-159 D-E).',
            $resultId,
            $reason,
        ));
    }

    public function errorCode(): string
    {
        return 'lab_result_not_releasable';
    }
}
