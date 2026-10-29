<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A pharmacist accepts a prescription into the pharmacy
 * (16-pharmacy-domain.md §19, §24; ADR-181 D-E).
 *
 * RECEIVED → ACTIVE. The handler fetches the prescribing decision through the
 * PROTECTED `ClinicalApi::getPrescribingDecision` — under the acting
 * pharmacist's own authorization (they must hold `clinical.prescribing.read`
 * and be scoped to the originating facility) — and writes the immutable,
 * SET-ONCE clinical snapshot (ADR-181 C1 / C3; ADR-143 D-Q). No clinical PHI
 * is carried on this command.
 */
final readonly class AcceptPrescription implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $prescriptionId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($prescriptionId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AcceptPrescription ids must be UUIDs (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'accept';
    }

    public function subjectId(): string
    {
        return $this->prescriptionId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'pharmacy.prescription_accepted',
            actorId: $this->actorId,
            targetType: 'prescription',
            targetId: $this->prescriptionId,
        );
    }
}
