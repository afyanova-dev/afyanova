<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Model;

use App\Domains\Insurance\Database\Factories\PayerTariffFactory;
use App\Domains\Insurance\Domain\Exception\IllegalPayerTariffTransition;
use App\Domains\Insurance\Domain\Money;
use App\Domains\Insurance\Domain\ValueObject\CoveredServiceCategory;
use App\Domains\Insurance\Domain\ValueObject\PayerTariffStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use InvalidArgumentException;

/**
 * One effective-dated contracted allowed rate for a (contract, service_category) scope
 * (21-insurance-and-payer-domain.md §51–§55, §114–§116; ADR-112; ADR-203).
 *
 * Follows the bill_tariffs (Tariff) / ins_plan_coverage_rules (PlanCoverageRule) shape:
 * effective-dated, amount IMMUTABLE once written, closed exactly once via
 * supersede() or end() — never re-opened. No events (configuration).
 *
 * @domain      Insurance
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    not-applicable
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contract_id
 * @property string $payer_id
 * @property string $service_category
 * @property string $amount
 * @property string $currency
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property string $status
 * @property string|null $created_by_user_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class PayerTariff extends AggregateRoot
{
    /** @use HasFactory<PayerTariffFactory> */
    use HasFactory;

    public const STATUS_CURRENT = PayerTariffStatus::CURRENT;

    public const STATUS_SUPERSEDED = PayerTariffStatus::SUPERSEDED;

    public const STATUS_ENDED = PayerTariffStatus::ENDED;

    protected $table = 'ins_payer_tariffs';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'contract_id', 'payer_id', 'service_category',
        'amount', 'currency',
        'effective_from', 'effective_to', 'status',
        'created_by_user_id',
        'occurred_at', 'recorded_at',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'amount' => 'string',
        'effective_from' => 'immutable_date',
        'effective_to' => 'immutable_date',
        'occurred_at' => 'immutable_datetime',
        'recorded_at' => 'immutable_datetime',
        'version' => 'integer',
    ];

    protected static function newFactory(): PayerTariffFactory
    {
        return PayerTariffFactory::new();
    }

    public static function open(
        string $id,
        string $tenantId,
        string $contractId,
        string $payerId,
        string $serviceCategory,
        string $amount,
        string $currency,
        CarbonImmutable $effectiveFrom,
        ?string $createdByUserId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        CoveredServiceCategory::assert($serviceCategory);

        if ($currency !== 'TZS') {
            throw new InvalidArgumentException(sprintf('Only TZS currency is supported, got [%s].', $currency));
        }

        $normalisedAmount = Money::of($amount);
        if (Money::isNegative($normalisedAmount)) {
            throw new InvalidArgumentException('Tariff amount must not be negative.');
        }

        $tariff = new self;
        $tariff->id = $id;
        $tariff->tenant_id = $tenantId;
        $tariff->contract_id = $contractId;
        $tariff->payer_id = $payerId;
        $tariff->service_category = $serviceCategory;
        $tariff->amount = $normalisedAmount;
        $tariff->currency = $currency;
        $tariff->effective_from = $effectiveFrom;
        $tariff->effective_to = null;
        $tariff->status = self::STATUS_CURRENT;
        $tariff->created_by_user_id = $createdByUserId;
        $tariff->occurred_at = $occurredAt;
        $tariff->recorded_at = $recordedAt;
        $tariff->version = 0;

        return $tariff;
    }

    public function supersede(CarbonImmutable $effectiveTo): void
    {
        if ($this->status !== self::STATUS_CURRENT) {
            throw IllegalPayerTariffTransition::cannotTransition(
                $this->id,
                $this->status,
                self::STATUS_SUPERSEDED,
            );
        }

        $this->effective_to = $effectiveTo;
        $this->status = self::STATUS_SUPERSEDED;
    }

    public function end(CarbonImmutable $effectiveTo): void
    {
        if ($this->status !== self::STATUS_CURRENT) {
            throw IllegalPayerTariffTransition::cannotTransition(
                $this->id,
                $this->status,
                self::STATUS_ENDED,
            );
        }

        $this->effective_to = $effectiveTo;
        $this->status = self::STATUS_ENDED;
    }

    public function isCurrent(): bool
    {
        return $this->status === self::STATUS_CURRENT;
    }

    public function isEffectiveOn(CarbonImmutable $date): bool
    {
        if ($date->lt($this->effective_from)) {
            return false;
        }

        if ($this->effective_to !== null && $date->gte($this->effective_to)) {
            return false;
        }

        return true;
    }
}
