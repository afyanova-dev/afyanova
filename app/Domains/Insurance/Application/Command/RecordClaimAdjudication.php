<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\ValueObject\AdjudicationStatus;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Records a third-party payer's formal adjudication on a submitted claim
 * (21-insurance-and-payer-domain.md §84–§87; ADR-205).
 *
 * Class-level `manage` (`insurance.adjudications.manage`).
 *
 * @param list<array{
 *     claimLineId: string,
 *     status: string,
 *     claimedAmount: string,
 *     approvedAmount: string,
 *     deniedAmount: string,
 *     patientResponsibility: string,
 *     contractualAdjustment: string,
 *     denialReasonCode?: ?string,
 *     remarks?: ?string,
 * }> $lines
 */
final readonly class RecordClaimAdjudication implements Auditable, AuthorizesAgainst
{
    public string $status;

    public ?string $externalReference;

    public ?string $notes;

    /**
     * @param  list<array{
     *     claimLineId: string,
     *     status: string,
     *     claimedAmount: string,
     *     approvedAmount: string,
     *     deniedAmount: string,
     *     patientResponsibility: string,
     *     contractualAdjustment: string,
     *     denialReasonCode?: ?string,
     *     remarks?: ?string,
     * }>  $lines
     */
    public function __construct(
        public string $adjudicationId,
        public string $actorId,
        public string $claimId,
        string $status,
        public string $adjudicationDate,
        public array $lines,
        ?string $externalReference = null,
        ?string $notes = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['adjudicationId' => $adjudicationId, 'actorId' => $actorId, 'claimId' => $claimId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordClaimAdjudication::\${$field} must be a UUID (IC §3).");
            }
        }

        $this->status = AdjudicationStatus::assert($status);
        $this->externalReference = self::clean($externalReference);
        $this->notes = self::clean($notes);

        if (empty($lines)) {
            throw new InvalidArgumentException('RecordClaimAdjudication::$lines must contain at least one line item.');
        }

        foreach ($lines as $i => $line) {
            if (! Str::isUuid($line['claimLineId'])) {
                throw new InvalidArgumentException("RecordClaimAdjudication::\$lines[{$i}]['claimLineId'] must be a UUID (IC §3).");
            }
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.adjudication_recorded',
            actorId: $this->actorId,
            targetType: 'adjudication',
            targetId: $this->adjudicationId,
            metadata: [
                'claim_id' => $this->claimId,
                'status' => $this->status,
                'adjudication_date' => $this->adjudicationDate,
                'line_count' => count($this->lines),
                'external_reference' => $this->externalReference,
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
