<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Model;

use App\Domains\Billing\Contracts\Event\AdjustmentApplied;
use App\Domains\Billing\Contracts\Event\AdjustmentReversed;
use App\Domains\Billing\Database\Factories\AdjustmentFactory;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ValueObject\FinancialControlStatus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * A post-charge account-level financial adjustment (20-billing-and-payments-domain.md
 * §81 / §82; ADR-191 G-3 / D7-D).
 *
 * The Bill.7a `WriteOff` shape — `REQUESTED → APPLIED → REVERSED` (+ `REJECTED`),
 * the shared `FinancialControlLifecycle`. `apply()` + the signed ledger
 * `ADJUSTMENT` are one atomic step. The amount / type are immutable. NEVER
 * touches `bill_charges` / `bill_invoices`; `invoice_id` is a reporting tag.
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
 * @property string|null $invoice_id
 * @property string $amount
 * @property string $currency
 * @property string $adjustment_type
 * @property string $direction
 * @property string $status
 * @property string|null $note
 * @property bool $dual_control
 * @property CarbonImmutable $requested_at
 * @property string $requested_by_user_id
 * @property CarbonImmutable|null $approved_at
 * @property string|null $approved_by_user_id
 * @property CarbonImmutable|null $rejected_at
 * @property string|null $rejected_by_user_id
 * @property string|null $reject_reason_code
 * @property string|null $reversal_id
 * @property CarbonImmutable|null $reversed_at
 * @property string|null $reversed_by_user_id
 * @property string|null $reverse_reason_code
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class Adjustment extends AggregateRoot
{
    /** @use HasFactory<AdjustmentFactory> */
    use HasFactory;

    public const STATUS_REQUESTED = FinancialControlStatus::REQUESTED;

    public const STATUS_APPLIED = FinancialControlStatus::APPLIED;

    public const STATUS_REVERSED = FinancialControlStatus::REVERSED;

    public const STATUS_REJECTED = FinancialControlStatus::REJECTED;

    protected $table = 'bill_adjustments';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'financial_account_id', 'patient_id', 'invoice_id',
        'amount', 'currency', 'adjustment_type', 'direction', 'status', 'note', 'dual_control',
        'requested_at', 'requested_by_user_id',
        'approved_at', 'approved_by_user_id',
        'rejected_at', 'rejected_by_user_id', 'reject_reason_code',
        'reversal_id', 'reversed_at', 'reversed_by_user_id', 'reverse_reason_code',
        'occurred_at', 'recorded_at', 'version',
    ];

    protected static function newFactory(): AdjustmentFactory
    {
        return AdjustmentFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'dual_control' => 'boolean',
            'requested_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @param  numeric-string  $amount  scale-2, > 0
     */
    public static function request(
        string $id,
        string $tenantId,
        string $facilityId,
        string $financialAccountId,
        string $patientId,
        ?string $invoiceId,
        string $amount,
        string $currency,
        string $adjustmentType,
        string $direction,
        ?string $note,
        bool $dualControl,
        string $requestedByUserId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'financial_account_id' => $financialAccountId,
            'patient_id' => $patientId,
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'currency' => $currency,
            'adjustment_type' => $adjustmentType,
            'direction' => $direction,
            'status' => self::STATUS_REQUESTED,
            'note' => $note,
            'dual_control' => $dualControl,
            'requested_at' => $occurredAt,
            'requested_by_user_id' => $requestedByUserId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /** REQUESTED → APPLIED. Records `AdjustmentApplied`. The caller posts the signed ledger entry. */
    public function apply(string $approvedByUserId): void
    {
        $now = CarbonImmutable::now();
        $this->status = self::STATUS_APPLIED;
        $this->approved_at = $now;
        $this->approved_by_user_id = $approvedByUserId;

        $this->recordThat(new AdjustmentApplied(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            financialAccountId: (string) $this->financial_account_id,
            adjustmentId: (string) $this->id,
            amount: Money::of((string) $this->amount),
            currency: (string) $this->currency,
            adjustmentType: (string) $this->adjustment_type,
            direction: (string) $this->direction,
            appliedByUserId: $approvedByUserId,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function reject(string $rejectedByUserId, string $reasonCode): void
    {
        $this->status = self::STATUS_REJECTED;
        $this->rejected_at = CarbonImmutable::now();
        $this->rejected_by_user_id = $rejectedByUserId;
        $this->reject_reason_code = $reasonCode;
    }

    /** APPLIED → REVERSED. Records `AdjustmentReversed`. The caller posts the OPPOSITE-direction entry. */
    public function reverse(string $reversalId, string $reversedByUserId, string $reasonCode): void
    {
        $now = CarbonImmutable::now();
        $this->status = self::STATUS_REVERSED;
        $this->reversal_id = $reversalId;
        $this->reversed_at = $now;
        $this->reversed_by_user_id = $reversedByUserId;
        $this->reverse_reason_code = $reasonCode;

        $this->recordThat(new AdjustmentReversed(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            financialAccountId: (string) $this->financial_account_id,
            adjustmentId: (string) $this->id,
            reversalId: $reversalId,
            amount: Money::of((string) $this->amount),
            currency: (string) $this->currency,
            reverseReasonCode: $reasonCode,
            reversedByUserId: $reversedByUserId,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    /** The compensating direction for a reversal — the opposite of the applied one. */
    public function reversalDirection(): string
    {
        return (string) $this->direction === 'CREDIT' ? 'DEBIT' : 'CREDIT';
    }
}
