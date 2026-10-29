<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Promote a patient identifier from UNVERIFIED to VERIFIED — entered data
 * becomes verified identity evidence (10-patient-domain.md §42, §130, §167;
 * ADR-117). Audited (§103). A VERIFIED national / insurance identifier is
 * uniqueness-constrained (ADR-117) and a BLOCK signal for duplicate detection.
 */
final readonly class VerifyPatientIdentifier implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $identifierId,
        public string $actorId,
        public ?string $verificationSource = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($identifierId)) {
            throw new InvalidArgumentException('VerifyPatientIdentifier::$identifierId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('VerifyPatientIdentifier::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'verify';
    }

    public function subjectId(): string
    {
        return $this->identifierId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'patient.identifier_verified',
            actorId: $this->actorId,
            targetType: 'patient_identifier',
            targetId: $this->identifierId,
            reason: $this->reason,
            metadata: [
                'verification_source' => $this->verificationSource,
            ],
        );
    }
}
