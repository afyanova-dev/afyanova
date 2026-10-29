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
 * Records an incoming payer remittance advice batch (ADR-205).
 * Class-level `record` (`insurance.remittance.record`).
 *
 * @param list<array{
 *     claimId?: ?string,
 *     adjudicationId?: ?string,
 *     externalClaimReference?: ?string,
 *     claimedAmount: string,
 *     paidAmount: string,
 *     contractualAdjustment: string,
 *     patientResponsibility: string,
 *     deniedAmount: string,
 *     status?: string,
 *     discrepancyReason?: ?string,
 * }> $lines
 */
final readonly class RecordRemittance implements Auditable, AuthorizesAgainst
{
    public string $totalAmount;

    public string $paymentReference;

    public string $paymentMethod;

    public ?string $notes;

    /**
     * @param  list<array{
     *     claimId?: ?string,
     *     adjudicationId?: ?string,
     *     externalClaimReference?: ?string,
     *     claimedAmount: string,
     *     paidAmount: string,
     *     contractualAdjustment: string,
     *     patientResponsibility: string,
     *     deniedAmount: string,
     *     status?: string,
     *     discrepancyReason?: ?string,
     * }>  $lines
     */
    public function __construct(
        public string $remittanceId,
        public string $actorId,
        public string $facilityId,
        public string $payerId,
        string $paymentReference,
        string $paymentMethod,
        public string $remittanceDate,
        string $totalAmount,
        public array $lines = [],
        ?string $notes = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['remittanceId' => $remittanceId, 'actorId' => $actorId, 'facilityId' => $facilityId, 'payerId' => $payerId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordRemittance::\${$field} must be a UUID (IC §3).");
            }
        }

        if (trim($paymentReference) === '') {
            throw new InvalidArgumentException('RecordRemittance::$paymentReference cannot be empty.');
        }

        $this->paymentReference = trim($paymentReference);
        $this->paymentMethod = trim($paymentMethod) === '' ? 'BANK_TRANSFER' : trim($paymentMethod);
        $this->totalAmount = Money::of($totalAmount);
        $this->notes = self::clean($notes);

        if (bccomp($this->totalAmount, '0', 2) <= 0) {
            throw new InvalidArgumentException('RecordRemittance::$totalAmount must be positive.');
        }
    }

    public function policyAbility(): string
    {
        return 'record';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.remittance_recorded',
            actorId: $this->actorId,
            targetType: 'remittance',
            targetId: $this->remittanceId,
            metadata: [
                'payer_id' => $this->payerId,
                'facility_id' => $this->facilityId,
                'payment_reference' => $this->paymentReference,
                'total_amount' => $this->totalAmount,
                'line_count' => count($this->lines),
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
