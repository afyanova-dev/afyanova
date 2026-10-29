<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record a patient identity as deceased at the identity level
 * (10-patient-domain.md §45, §130; ADR-116). A sensitive administrative
 * operation — audited (§45, §103). Clinical documentation of the death is
 * Clinical's, not this command's.
 */
final readonly class MarkPatientDeceased implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $patientId,
        public string $actorId,
        public ?string $reason = null,
    ) {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('MarkPatientDeceased::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('MarkPatientDeceased::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'markDeceased';
    }

    public function subjectId(): string
    {
        return $this->patientId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'patient.marked_deceased',
            actorId: $this->actorId,
            targetType: 'patient',
            targetId: $this->patientId,
            reason: $this->reason,
        );
    }
}
