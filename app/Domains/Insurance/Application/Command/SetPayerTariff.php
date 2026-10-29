<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\Money;
use App\Domains\Insurance\Domain\ValueObject\CoveredServiceCategory;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Sets a new effective-dated contracted tariff for a (contract, service_category)
 * (21-insurance-and-payer-domain.md §51–§55; ADR-203). Class-level `manage` (`insurance.tariffs.manage`).
 */
final readonly class SetPayerTariff implements Auditable, AuthorizesAgainst
{
    public string $serviceCategory;

    /** @var numeric-string */
    public string $amount;

    public CarbonImmutable $effectiveFrom;

    public function __construct(
        public string $tariffId,
        public string $actorId,
        public string $contractId,
        string $serviceCategory,
        string $amount,
        string $effectiveFrom,
        public string $currency = 'TZS',
        public ?string $idempotencyKey = null,
    ) {
        foreach (['tariffId' => $tariffId, 'actorId' => $actorId, 'contractId' => $contractId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("SetPayerTariff::\${$field} must be a UUID (IC §3).");
            }
        }

        $this->serviceCategory = CoveredServiceCategory::assert($serviceCategory);
        $this->amount = Money::of($amount);

        if (Money::isNegative($this->amount)) {
            throw new InvalidArgumentException('SetPayerTariff::$amount must not be negative.');
        }

        if ($this->currency !== 'TZS') {
            throw new InvalidArgumentException(sprintf('Only TZS currency is supported, got [%s].', $this->currency));
        }

        try {
            $this->effectiveFrom = CarbonImmutable::parse($effectiveFrom)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('SetPayerTariff::$effectiveFrom must be a valid date.');
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
            action: 'insurance.payer_tariff_set',
            actorId: $this->actorId,
            targetType: 'payer_tariff',
            targetId: $this->tariffId,
            metadata: [
                'contract_id' => $this->contractId,
                'service_category' => $this->serviceCategory,
                'amount' => $this->amount,
                'effective_from' => $this->effectiveFrom->toDateString(),
            ],
        );
    }
}
