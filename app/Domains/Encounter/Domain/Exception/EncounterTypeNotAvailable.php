<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Domain\Exception;

use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Shared\Domain\DomainException;

/**
 * The target facility does not have the capability an encounter type requires
 * ENABLED (11-encounter-domain.md §11, Invariant 19; ADR-120, ADR-130). The
 * client cannot override the check. Only OUTPATIENT / EMERGENCY / INPATIENT /
 * MATERNITY are capability-gated in PHASE 5 (ADR-120). Maps to HTTP 409 (§15).
 */
final class EncounterTypeNotAvailable extends DomainException
{
    public static function for(EncounterType $type, string $capabilityCode, string $facilityId): self
    {
        return new self(sprintf(
            'Encounter type %s is not available at facility [%s] — capability %s is not ENABLED.',
            $type->value,
            $facilityId,
            $capabilityCode,
        ));
    }

    public function errorCode(): string
    {
        return 'encounter_type_not_available';
    }
}
