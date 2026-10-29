<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Model;

use App\Domains\Billing\Contracts\Event\PaymentRecorded;
use App\Domains\Billing\Contracts\Event\PaymentReversed;
use App\Domains\Billing\Database\Factories\PaymentFactory;
use App\Domains\Billing\Domain\Exception\IllegalPaymentTransition;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ValueObject\PaymentStatus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Money actually received against a Financial Account
 * (20-billing-and-payments-domain.md §57 / §288; ADR-191 B5-B / B5-C / G-6 / G-7).
 *
 * A Payment posts ONE `PAYMENT` / `CREDIT` ledger entry for its FULL amount at
 * `RecordPayment` (B5-B) — the account `outstanding` may go negative (= patient
 * credit). It carries NO allocations in Bill.5b; `AllocatePayment` (Bill.5c)
 * distributes the money onto invoices with NO further ledger entry. `RECORDED →
 * REVERSED` only (B5-C — no gateway lifecycle). A reversal is a status
 * transition on this row (metadata below) + a compensating `PAYMENT` / `DEBIT`
 * ledger entry — NOT a separate append-only sub-record.
 *
 * `facility_id` is the OWNING ACCOUNT's facility (financial truth — G-7);
 * `collected_at_facility_id` + `collected_by_user_id` are the collection point
 * (§150 / §151 — a facility may collect for another facility's account).
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
 * @property string $method
 * @property string $amount
 * @property string $currency
 * @property string $status
 * @property string|null $payment_reference
 * @property string $collected_at_facility_id
 * @property string $collected_by_user_id
 * @property string|null $cash_session_id
 * @property CarbonImmutable $received_at
 * @property string|null $reversal_id
 * @property CarbonImmutable|null $reversed_at
 * @property string|null $reversed_by_user_id
 * @property string|null $reverse_reason_code
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class Payment extends AggregateRoot
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    public const STATUS_RECORDED = PaymentStatus::RECORDED;

    public const STATUS_REVERSED = PaymentStatus::REVERSED;

    protected $table = 'bill_payments';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'financial_account_id', 'patient_id',
        'method', 'amount', 'currency', 'status',
        'payment_reference', 'collected_at_facility_id', 'collected_by_user_id', 'cash_session_id',
        'received_at',
        'reversal_id', 'reversed_at', 'reversed_by_user_id', 'reverse_reason_code',
        'occurred_at', 'recorded_at', 'version',
    ];

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'received_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @param  numeric-string  $amount  scale-2, > 0
     */
    public static function record(
        string $id,
        string $tenantId,
        string $facilityId,
        string $financialAccountId,
        string $patientId,
        string $method,
        string $amount,
        string $currency,
        ?string $paymentReference,
        string $collectedAtFacilityId,
        string $collectedByUserId,
        ?string $cashSessionId,
        CarbonImmutable $receivedAt,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        $payment = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'financial_account_id' => $financialAccountId,
            'patient_id' => $patientId,
            'method' => $method,
            'amount' => $amount,
            'currency' => $currency,
            'status' => self::STATUS_RECORDED,
            'payment_reference' => $paymentReference,
            'collected_at_facility_id' => $collectedAtFacilityId,
            'collected_by_user_id' => $collectedByUserId,
            'cash_session_id' => $cashSessionId,
            'received_at' => $receivedAt,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);

        $payment->recordThat(new PaymentRecorded(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $patientId,
            financialAccountId: $financialAccountId,
            paymentId: $id,
            method: $method,
            amount: $amount,
            currency: $currency,
            occurredAt: $occurredAt->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $payment;
    }

    /** RECORDED → REVERSED (§70). Idempotent — a second call on a REVERSED payment is a no-op. */
    public function reverse(string $reversalId, string $reversedByUserId, string $reasonCode): void
    {
        if ($this->status === self::STATUS_REVERSED) {
            return;
        }

        if ($this->status !== self::STATUS_RECORDED) {
            throw IllegalPaymentTransition::fromStatus('ReversePayment', (string) $this->status);
        }

        $now = CarbonImmutable::now();
        $this->status = self::STATUS_REVERSED;
        $this->reversal_id = $reversalId;
        $this->reversed_at = $now;
        $this->reversed_by_user_id = $reversedByUserId;
        $this->reverse_reason_code = $reasonCode;

        $this->recordThat(new PaymentReversed(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            financialAccountId: (string) $this->financial_account_id,
            paymentId: (string) $this->id,
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

    public function isRecorded(): bool
    {
        return $this->status === self::STATUS_RECORDED;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }
}
