<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Model;

use App\Domains\Billing\Contracts\Event\ChargeCreated;
use App\Domains\Billing\Contracts\Event\ChargeReversed;
use App\Domains\Billing\Database\Factories\ChargeFactory;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ValueObject\ChargeStatus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * The facility charge for one consumed billable item/service — one per
 * `pharmacy.MedicationDispensed` (20-billing-and-payments-domain.md §14 / §286 /
 * §299; ADR-189 D-C).
 *
 * ADR-189 BOUNDARY — a Charge does NOT represent payer liability, insurance
 * coverage, co-payment, claim submission or reimbursement. The `amount` columns
 * are immutable; only `status` (POSTED → REVERSED) + `reversal_id` change.
 *
 * @domain      Billing
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $financial_account_id
 * @property string $patient_id
 * @property string $billing_service_id
 * @property string $billing_service_code
 * @property string $billable_event_id
 * @property string $source_ref
 * @property string $quantity
 * @property string $unit_price
 * @property string $tariff_row_id
 * @property string $resolved_scope
 * @property string $amount
 * @property string $currency
 * @property string $status
 * @property string|null $reversal_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class Charge extends AggregateRoot
{
    /** @use HasFactory<ChargeFactory> */
    use HasFactory;

    public const STATUS_POSTED = ChargeStatus::POSTED;

    public const STATUS_REVERSED = ChargeStatus::REVERSED;

    protected $table = 'bill_charges';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'financial_account_id', 'patient_id',
        'billing_service_id', 'billing_service_code', 'billable_event_id', 'source_ref',
        'quantity', 'unit_price', 'tariff_row_id', 'resolved_scope', 'amount', 'currency',
        'status', 'reversal_id',
        'occurred_at', 'recorded_at', 'version',
    ];

    protected static function newFactory(): ChargeFactory
    {
        return ChargeFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'amount' => 'decimal:4',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @param  numeric-string  $quantity
     * @param  numeric-string  $unitPrice  scale-2
     * @param  numeric-string  $amount  scale-2 — Money::multiply(unitPrice, quantity), HALF-UP
     */
    public static function post(
        string $id,
        string $tenantId,
        string $facilityId,
        string $financialAccountId,
        string $patientId,
        string $billingServiceId,
        string $billingServiceCode,
        string $billableEventId,
        string $sourceRef,
        string $quantity,
        string $unitPrice,
        string $tariffRowId,
        string $resolvedScope,
        string $amount,
        string $currency,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        $charge = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'financial_account_id' => $financialAccountId,
            'patient_id' => $patientId,
            'billing_service_id' => $billingServiceId,
            'billing_service_code' => $billingServiceCode,
            'billable_event_id' => $billableEventId,
            'source_ref' => $sourceRef,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'tariff_row_id' => $tariffRowId,
            'resolved_scope' => $resolvedScope,
            'amount' => $amount,
            'currency' => $currency,
            'status' => self::STATUS_POSTED,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);

        $charge->recordThat(new ChargeCreated(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $patientId,
            financialAccountId: $financialAccountId,
            chargeId: $id,
            billingServiceId: $billingServiceId,
            billableEventId: $billableEventId,
            amount: $amount,
            currency: $currency,
            occurredAt: $occurredAt->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $charge;
    }

    public function markReversed(string $reversalId): void
    {
        $this->status = self::STATUS_REVERSED;
        $this->reversal_id = $reversalId;

        $this->recordThat(new ChargeReversed(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            chargeId: (string) $this->id,
            chargeReversalId: $reversalId,
            financialAccountId: (string) $this->financial_account_id,
            amount: Money::of((string) $this->amount),
            currency: (string) $this->currency,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }
}
