<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\Money;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reconciles an individual remittance line against an adjudicated claim
 * (21-insurance-and-payer-domain.md §98–§104; ADR-205).
 *
 * Per-subject `record` (`insurance.remittance.record`).
 */
final readonly class ReconcileRemittanceLine implements Auditable, AuthorizesAgainst
{
    public string $paidAmount;

    public string $contractualAdjustment;

    public string $patientResponsibility;

    public string $deniedAmount;

    public ?string $discrepancyReason;

    public function __construct(
        public string $remittanceId,
        public string $actorId,
        public string $remittanceLineId,
        public string $claimId,
        string $paidAmount,
        string $contractualAdjustment = '0.00',
        string $patientResponsibility = '0.00',
        string $deniedAmount = '0.00',
        ?string $discrepancyReason = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['remittanceId' => $remittanceId, 'actorId' => $actorId, 'remittanceLineId' => $remittanceLineId, 'claimId' => $claimId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("ReconcileRemittanceLine::\${$field} must be a UUID (IC §3).");
            }
        }

        $this->paidAmount = Money::of($paidAmount);
        $this->contractualAdjustment = Money::of($contractualAdjustment);
        $this->patientResponsibility = Money::of($patientResponsibility);
        $this->deniedAmount = Money::of($deniedAmount);
        $this->discrepancyReason = self::clean($discrepancyReason);

        if (bccomp($this->paidAmount, '0', 2) < 0) {
            throw new InvalidArgumentException('ReconcileRemittanceLine::$paidAmount cannot be negative.');
        }
    }

    public function policyAbility(): string
    {
        return 'record';
    }

    public function subjectId(): string
    {
        return $this->remittanceId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.remittance_line_reconciled',
            actorId: $this->actorId,
            targetType: 'remittance',
            targetId: $this->remittanceId,
            metadata: [
                'remittance_line_id' => $this->remittanceLineId,
                'claim_id' => $this->claimId,
                'paid_amount' => $this->paidAmount,
                'contractual_adjustment' => $this->contractualAdjustment,
                'patient_responsibility' => $this->patientResponsibility,
                'discrepancy_reason' => $this->discrepancyReason,
            ],
        );
    }

    private static function clean(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim($v);

        return $t === '' ? null : $t;
    }
}
