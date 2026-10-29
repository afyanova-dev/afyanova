<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Move an INACTIVE patient identity back to ACTIVE (10-patient-domain.md §44,
 * §130; ADR-116). Audited (§103).
 */
final readonly class ReactivatePatient implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $patientId,
        public string $actorId,
        public ?string $reason = null,
    ) {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('ReactivatePatient::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ReactivatePatient::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'reactivate';
    }

    public function subjectId(): string
    {
        return $this->patientId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'patient.reactivated',
            actorId: $this->actorId,
            targetType: 'patient',
            targetId: $this->patientId,
            reason: $this->reason,
        );
    }
}
