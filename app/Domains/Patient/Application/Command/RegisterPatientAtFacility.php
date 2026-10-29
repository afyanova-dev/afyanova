<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record a patient's participation at a facility (10-patient-domain.md
 * §109-111; ADR-114). Internal command backing PatientApi::registerAtFacility
 * — its two-parameter signature matches the ratified ADR-114/125 port
 * exactly. Called by another domain's already-authorized flow (its
 * `$facilityId` is that caller's own established TenantContext, never raw
 * client input — ADR-037, Invariant 20).
 *
 * NOT Auditable: the ADR-125 port signature carries no actor, and there is no
 * other channel to supply one for this command (unlike RegisterPatient, which
 * is dispatched directly by a human action and carries actorId).
 */
final readonly class RegisterPatientAtFacility implements AuthorizesAgainst
{
    public function __construct(
        public string $patientId,
        public string $facilityId,
    ) {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('RegisterPatientAtFacility::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('RegisterPatientAtFacility::$facilityId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'registerAtFacility';
    }

    public function subjectId(): ?string
    {
        return null; // create — class-level check (IC §5.1)
    }
}
