<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\ValueObject\BillingServiceStatus;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Set a billing service ACTIVE or INACTIVE (ADR-188 D-C). Per-subject `manage`.
 * Taking a service INACTIVE does NOT touch its `bill_tariffs` rows (F5);
 * `GetEffectiveTariff` stays status-agnostic — Bill.3 gates on `serviceStatus`.
 */
final readonly class SetBillingServiceStatus implements Auditable, AuthorizesAgainst
{
    public string $status;

    public function __construct(
        public string $billingServiceId,
        public string $actorId,
        string $status,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($billingServiceId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('SetBillingServiceStatus ids must be UUIDs (IC §3).');
        }

        $this->status = BillingServiceStatus::assert($status);
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->billingServiceId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'billing.billing_service_status_set',
            actorId: $this->actorId,
            targetType: 'billing_service',
            targetId: $this->billingServiceId,
            reason: $this->status,
        );
    }
}
