<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RequestProcedure` named a `ProcedureDefinition` whose `required_capability_code`
 * is not ENABLED at the target facility (13-clinical-procedures-domain.md §22–§23,
 * §147 Inv 26–27; ADR-146 D-G — the ADR-120 `FacilityCapabilities::has`
 * precedent). Capability availability is facility-specific, never inferred from
 * `facility_type` (ADR-005). Maps to HTTP 409 (IC §15).
 */
final class ProcedureCapabilityUnavailable extends DomainException
{
    public static function forDefinition(string $facilityId, string $capabilityCode): self
    {
        return new self(sprintf(
            'Facility [%s] does not have capability [%s] enabled — this procedure cannot be requested there (13 §22, ADR-120).',
            $facilityId,
            $capabilityCode,
        ));
    }

    public function errorCode(): string
    {
        return 'procedure_capability_unavailable';
    }
}
