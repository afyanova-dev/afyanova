<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Close the open tariff row for a scope with no replacement
 * (20-billing-and-payments-domain.md §28, §106; ADR-188 D-C). Class-level
 * `manage`. The scope is unpriced from `effectiveTo` onward — an explicit
 * business state (unpriced ≠ no-charge).
 */
final readonly class EndTariff implements Auditable, AuthorizesAgainst
{
    public CarbonImmutable $effectiveTo;

    public function __construct(
        public string $actorId,
        public string $billingServiceId,
        public ?string $facilityId,
        string $effectiveTo,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($actorId) || ! Str::isUuid($billingServiceId)) {
            throw new InvalidArgumentException('EndTariff ids must be UUIDs (IC §3).');
        }
        if ($facilityId !== null && ! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('EndTariff::$facilityId must be a UUID or null (IC §3).');
        }

        try {
            $this->effectiveTo = CarbonImmutable::parse($effectiveTo)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('EndTariff::$effectiveTo must be a valid date.');
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
            action: 'billing.tariff_ended',
            actorId: $this->actorId,
            targetType: 'billing_service',
            targetId: $this->billingServiceId,
            metadata: [
                'scope' => $this->facilityId ?? 'TENANT_DEFAULT',
                'effective_to' => $this->effectiveTo->toDateString(),
            ],
        );
    }
}
