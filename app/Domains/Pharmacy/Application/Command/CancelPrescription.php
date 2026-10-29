<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Administratively cancel a prescription record
 * (16-pharmacy-domain.md §55, §111; ADR-181 D-E). RECEIVED | ACTIVE → CANCELLED.
 *
 * This is Pharmacy's administrative cancellation of the RECORD (entered in
 * error / never collected / superseded) — NOT a clinical decision (§53–§55) and
 * NOT §54 clarification (deferred). `reason` is mandatory, non-blank, ROW-ONLY
 * (the ADR-136 deny-list — carried on the dedicated `AuditEntry` `reason:`
 * column, never in the event).
 */
final readonly class CancelPrescription implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $prescriptionId,
        public string $actorId,
        public string $reason,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($prescriptionId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CancelPrescription ids must be UUIDs (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('CancelPrescription::$reason must not be blank.');
        }
    }

    public function policyAbility(): string
    {
        return 'cancel';
    }

    public function subjectId(): string
    {
        return $this->prescriptionId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'pharmacy.prescription_cancelled',
            actorId: $this->actorId,
            targetType: 'prescription',
            targetId: $this->prescriptionId,
            reason: trim($this->reason),
        );
    }
}
