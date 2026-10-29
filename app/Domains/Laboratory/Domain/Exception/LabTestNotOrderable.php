<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateLabOrder` named a `lab_test_definitions` row that cannot be ordered:
 * inactive or DISABLED (§20, §125, §189 Inv 27), or its
 * `required_capability_code` is not ENABLED at the ordering facility
 * (§18, §115; ADR-120 — the CP.1 `ProcedureCapabilityUnavailable` precedent).
 * Maps to HTTP 409.
 */
final class LabTestNotOrderable extends DomainException
{
    public static function unavailable(string $definitionId): self
    {
        return new self(sprintf(
            'Laboratory test definition [%s] is inactive or DISABLED and cannot be ordered.',
            $definitionId,
        ));
    }

    public static function capabilityMissing(string $definitionId, string $capabilityCode, string $facilityId): self
    {
        return new self(sprintf(
            'Laboratory test definition [%s] requires capability [%s], which is not enabled at facility [%s].',
            $definitionId,
            $capabilityCode,
            $facilityId,
        ));
    }

    public function errorCode(): string
    {
        return 'lab_test_not_orderable';
    }
}
