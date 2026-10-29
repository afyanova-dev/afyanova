<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\ValueObject\PayerStatus;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Set a payer ACTIVE or INACTIVE (ADR-198 D-G). Per-subject `manage`.
 * INACTIVE never deletes history (§319 / §320); Ins.2 gates member coverage on
 * an ACTIVE payer.
 */
final readonly class SetPayerStatus implements Auditable, AuthorizesAgainst
{
    public string $status;

    public ?string $reason;

    public function __construct(
        public string $payerId,
        public string $actorId,
        string $status,
        ?string $reason = null,
    ) {
        if (! Str::isUuid($payerId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('SetPayerStatus ids must be UUIDs (IC §3).');
        }

        $this->status = PayerStatus::assert($status);
        $reason = $reason !== null ? trim($reason) : null;
        $this->reason = $reason === '' ? null : $reason;
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->payerId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.payer_status_set',
            actorId: $this->actorId,
            targetType: 'payer',
            targetId: $this->payerId,
            reason: $this->reason,
            metadata: ['status' => $this->status],
        );
    }
}
