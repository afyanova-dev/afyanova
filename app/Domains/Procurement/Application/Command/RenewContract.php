<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Exception\ContractDatesInvalid;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Renew an ACTIVE purchase contract (19-procurement-domain.md §195, §248, §303
 * Invariant 31; ADR-178 D-C). Mints a NEW `pcr_contracts` row
 * (`$newContractId`, client-minted UUIDv7) with a fresh `CON-%08d`, the old
 * contract's supplier / currency / terms (title overridable), a new term
 * (`effectiveDate < expiryDate`), `supersedes_contract_id` → the old contract,
 * and every `pcr_contract_lines` row RE-CREATED with FRESH ids carrying the same
 * price / ceiling / uom. Lands ACTIVE directly. The old contract → `SUPERSEDED`.
 *
 * Proc.3c carries lines forward verbatim — a price / line revision on renewal is
 * Proc.3d/4. `reason` is mandatory + ROW-ONLY. Authorization is checked against
 * the OLD contract (`subjectId()`).
 */
final readonly class RenewContract implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $newContractId,
        public string $contractId,
        public string $actorId,
        public string $effectiveDate,
        public string $expiryDate,
        public string $reason,
        public ?string $title = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['newContractId' => $newContractId, 'contractId' => $contractId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RenewContract::\${$name} must be a UUID (IC §3).");
            }
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('RenewContract::$reason must not be blank.');
        }

        if ($title !== null && trim($title) === '') {
            throw new InvalidArgumentException('RenewContract::$title, when given, must not be blank.');
        }

        $effectiveTs = strtotime($effectiveDate);
        $expiryTs = strtotime($expiryDate);
        if ($effectiveTs === false || $expiryTs === false) {
            throw new InvalidArgumentException('RenewContract::$effectiveDate and $expiryDate must be valid dates.');
        }
        if ($effectiveTs >= $expiryTs) {
            throw ContractDatesInvalid::effectiveNotBeforeExpiry($effectiveDate, $expiryDate);
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->contractId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.contract_renewed',
            actorId: $this->actorId,
            targetType: 'contract',
            targetId: $this->newContractId,
            reason: trim($this->reason),
            metadata: [
                'supersedes_contract_id' => $this->contractId,
            ],
        );
    }
}
