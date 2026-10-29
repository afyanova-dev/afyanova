<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RecordCriticalResult` named a result that already has a critical-result
 * record (14-laboratory-domain.md §54; ADR-161 D-D — `unique(tenant_id,
 * result_id)`). Maps to HTTP 409.
 */
final class CriticalResultAlreadyRecorded extends DomainException
{
    public static function forResult(string $resultId): self
    {
        return new self(sprintf('Result [%s] already has a critical-result record.', $resultId));
    }

    public function errorCode(): string
    {
        return 'critical_result_already_recorded';
    }
}
