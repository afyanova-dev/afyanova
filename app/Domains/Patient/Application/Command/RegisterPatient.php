<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Register a new patient identity (10-patient-domain.md §15; ADR-114, ADR-116,
 * ADR-117, ADR-119).
 *
 * Duplicate detection (ADR-117) runs inside the handler before any row is
 * written: BLOCK -> PatientRegistrationBlocked (409); REVIEW -> a
 * RegisterPatientReview result (no patient, PatientDuplicateDetected emitted);
 * ALLOW -> proceeds. `$nationalId` / `$insuranceMemberNumber` are detection
 * SIGNALS only — they are not persisted as pat_patient_identifiers rows here
 * (that is AddPatientIdentifier's job). `$overrideDuplicate` re-submits past a
 * BLOCK / REVIEW and additionally requires patient.patients.override_duplicate
 * (PatientPolicy::register); `$overrideReason` is then mandatory and is
 * audited.
 *
 * This command is NOT Auditable: the audit row depends on the outcome (only
 * ALLOW / override writes `patient.registered`, and an override carries extra
 * metadata), which the bus WriteAuditRow step cannot see — so
 * RegisterPatientHandler writes the audit itself. `$patientId` is client-minted
 * (a UUIDv7); `$tenantId` / `$facilityId` are resolved from TenantContext only,
 * never from the command (ADR-037, Invariant 20).
 */
final readonly class RegisterPatient implements AuthorizesAgainst
{
    public function __construct(
        public string $patientId,
        public string $actorId,
        public string $givenName,
        public string $familyName,
        public ?string $middleName,
        public ?string $displayName,
        public Sex $sex,
        public ?string $dateOfBirth,
        public DobPrecision $dobPrecision,
        public ?string $primaryPhone,
        public ?string $email,
        public bool $provisional = false,
        public ?string $source = null,
        public ?string $idempotencyKey = null,
        public ?string $nationalId = null,
        public ?string $insuranceMemberNumber = null,
        public bool $overrideDuplicate = false,
        public ?string $overrideReason = null,
        public ?string $photoDocumentId = null,
    ) {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('RegisterPatient::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RegisterPatient::$actorId must be a UUID (IC §3).');
        }

        if ($overrideDuplicate && ($overrideReason === null || trim($overrideReason) === '')) {
            throw new InvalidArgumentException('RegisterPatient::$overrideReason is required when overriding duplicate detection.');
        }
    }

    public function policyAbility(): string
    {
        return 'register';
    }

    public function subjectId(): ?string
    {
        return null; // create — class-level check (IC §5.1)
    }
}
