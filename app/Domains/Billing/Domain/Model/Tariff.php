<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Model;

use App\Domains\Billing\Database\Factories\TariffFactory;
use App\Domains\Billing\Domain\ValueObject\TariffStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One row of the base price list (20-billing-and-payments-domain.md §17,
 * §19–§24, §232–§235; ADR-188 D-B).
 *
 * Effective-dated. `amount` is immutable once written (`Money::of()`-normalised).
 * A row is closed exactly once — {@see self::supersede()} (a later `SetTariff`)
 * or {@see self::end()} (`EndTariff`, no replacement) — never re-opened. No
 * events (Bill.2 is configuration).
 *
 * @domain      Billing
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required   (a real facility id, or = tenant_id sentinel = tenant-wide default)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $billing_service_id
 * @property string $facility_id
 * @property string $amount
 * @property string $currency
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property string $status
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class Tariff extends AggregateRoot
{
    /** @use HasFactory<TariffFactory> */
    use HasFactory;

    public const STATUS_CURRENT = TariffStatus::CURRENT;

    public const STATUS_SUPERSEDED = TariffStatus::SUPERSEDED;

    public const STATUS_ENDED = TariffStatus::ENDED;

    protected $table = 'bill_tariffs';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'billing_service_id', 'facility_id',
        'amount', 'currency',
        'effective_from', 'effective_to', 'status',
        'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): TariffFactory
    {
        return TariffFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @param  numeric-string  $amount  already `Money::of()`-normalised (scale-2)
     */
    public static function open(
        string $id,
        string $tenantId,
        string $billingServiceId,
        string $facilityId,
        string $amount,
        string $currency,
        CarbonImmutable $effectiveFrom,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'billing_service_id' => $billingServiceId,
            'facility_id' => $facilityId,
            'amount' => $amount,
            'currency' => $currency,
            'effective_from' => $effectiveFrom,
            'effective_to' => null,
            'status' => self::STATUS_CURRENT,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /** CURRENT → SUPERSEDED — a later `SetTariff` takes over from `$at` (exclusive). */
    public function supersede(CarbonImmutable $at): void
    {
        $this->effective_to = $at;
        $this->status = self::STATUS_SUPERSEDED;
    }

    /** CURRENT → ENDED — `EndTariff`; the scope is unpriced from `$at` (exclusive). */
    public function end(CarbonImmutable $at): void
    {
        $this->effective_to = $at;
        $this->status = self::STATUS_ENDED;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_CURRENT;
    }
}
