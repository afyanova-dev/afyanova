<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates a new claim anchored to an ISSUED insured Billing invoice (ADR-204; Sponsor Ruling D2).
 * Class-level `create` (`insurance.claims.manage`).
 */
final readonly class CreateClaimFromInvoice implements Auditable, AuthorizesAgainst
{
    public ?string $primaryDiagnosisCode;

    public ?string $notes;

    public function __construct(
        public string $claimId,
        public string $actorId,
        public string $invoiceId,
        public string $facilityId,
        public ?string $contractId = null,
        public ?string $authorizationId = null,
        ?string $primaryDiagnosisCode = null,
        ?string $notes = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['claimId' => $claimId, 'actorId' => $actorId, 'invoiceId' => $invoiceId, 'facilityId' => $facilityId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CreateClaimFromInvoice::\${$field} must be a UUID (IC §3).");
            }
        }

        if ($contractId !== null && ! Str::isUuid($contractId)) {
            throw new InvalidArgumentException('CreateClaimFromInvoice::$contractId must be a UUID or null (IC §3).');
        }

        if ($authorizationId !== null && ! Str::isUuid($authorizationId)) {
            throw new InvalidArgumentException('CreateClaimFromInvoice::$authorizationId must be a UUID or null (IC §3).');
        }

        $this->primaryDiagnosisCode = self::clean($primaryDiagnosisCode);
        $this->notes = self::clean($notes);
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.claim_created',
            actorId: $this->actorId,
            targetType: 'claim',
            targetId: $this->claimId,
            metadata: [
                'invoice_id' => $this->invoiceId,
                'facility_id' => $this->facilityId,
                'contract_id' => $this->contractId,
                'authorization_id' => $this->authorizationId,
                'primary_diagnosis_code' => $this->primaryDiagnosisCode,
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
