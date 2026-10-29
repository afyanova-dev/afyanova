<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\Money;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Set a new effective-dated base price for a service
 * (20-billing-and-payments-domain.md §17, §19, §20; ADR-188 D-C). Class-level
 * `manage`.
 *
 * `facilityId` null → the tenant-wide default (stored as the sentinel
 * `facility_id = tenant_id`). `amount` is normalised to scale-2 (`Money`); a
 * value carrying sub-cent digits is rejected (F3). `currency` must be `TZS`
 * (F7). The handler closes the prior open row (exclusive `effective_to`) and
 * rejects an `effective_from` not strictly after it (F2).
 */
final readonly class SetTariff implements Auditable, AuthorizesAgainst
{
    /** @var numeric-string */
    public string $amount;

    public string $currency;

    public CarbonImmutable $effectiveFrom;

    public function __construct(
        public string $tariffId,
        public string $actorId,
        public string $billingServiceId,
        public ?string $facilityId,
        string $amount,
        string $effectiveFrom,
        string $currency = 'TZS',
        public ?string $idempotencyKey = null,
    ) {
        foreach (['tariffId' => $tariffId, 'actorId' => $actorId, 'billingServiceId' => $billingServiceId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("SetTariff::\${$name} must be a UUID (IC §3).");
            }
        }
        if ($facilityId !== null && ! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('SetTariff::$facilityId must be a UUID or null (IC §3).');
        }

        if (! is_numeric($amount) || bccomp($amount, '0', 4) <= 0) {
            throw new InvalidArgumentException('SetTariff::$amount must be a positive number.');
        }
        if (bccomp($amount, bcadd($amount, '0', 2), 4) !== 0) {
            throw new InvalidArgumentException('SetTariff::$amount must not carry sub-cent digits.');
        }

        $currency = strtoupper(trim($currency));
        if ($currency !== 'TZS') {
            throw new InvalidArgumentException('SetTariff::$currency must be TZS.');
        }

        try {
            $from = CarbonImmutable::parse($effectiveFrom)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('SetTariff::$effectiveFrom must be a valid date.');
        }

        $this->amount = Money::of($amount);
        $this->currency = $currency;
        $this->effectiveFrom = $from;
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
            action: 'billing.tariff_set',
            actorId: $this->actorId,
            targetType: 'tariff',
            targetId: $this->tariffId,
            metadata: [
                'billing_service_id' => $this->billingServiceId,
                'scope' => $this->facilityId ?? 'TENANT_DEFAULT',
                'amount' => $this->amount,
                'effective_from' => $this->effectiveFrom->toDateString(),
            ],
        );
    }
}
