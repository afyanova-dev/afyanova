<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Activates a DRAFT payer contract (21-insurance-and-payer-domain.md §49; ADR-203).
 * Per-subject `activate` (`insurance.contracts.manage`).
 */
final readonly class ActivatePayerContract implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $contractId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['contractId' => $contractId, 'actorId' => $actorId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("ActivatePayerContract::\${$field} must be a UUID (IC §3).");
            }
        }
    }

    public function policyAbility(): string
    {
        return 'activate';
    }

    public function subjectId(): string
    {
        return $this->contractId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.contract_activated',
            actorId: $this->actorId,
            targetType: 'contract',
            targetId: $this->contractId,
        );
    }
}
