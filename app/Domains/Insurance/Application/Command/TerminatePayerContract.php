<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Terminates a payer contract (21-insurance-and-payer-domain.md §49; ADR-203).
 * Per-subject `terminate` (`insurance.contracts.manage`).
 */
final readonly class TerminatePayerContract implements Auditable, AuthorizesAgainst
{
    public string $reason;

    public function __construct(
        public string $contractId,
        public string $actorId,
        string $reason,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['contractId' => $contractId, 'actorId' => $actorId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("TerminatePayerContract::\${$field} must be a UUID (IC §3).");
            }
        }

        $this->reason = trim($reason);
        if ($this->reason === '') {
            throw new InvalidArgumentException('TerminatePayerContract::$reason must not be empty.');
        }
    }

    public function policyAbility(): string
    {
        return 'terminate';
    }

    public function subjectId(): string
    {
        return $this->contractId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.contract_terminated',
            actorId: $this->actorId,
            targetType: 'contract',
            targetId: $this->contractId,
            metadata: [
                'reason' => $this->reason,
            ],
        );
    }
}
