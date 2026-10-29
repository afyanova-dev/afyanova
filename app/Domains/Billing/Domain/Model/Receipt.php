<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Model;

use App\Domains\Billing\Database\Factories\ReceiptFactory;
use App\Domains\Billing\Domain\ValueObject\ReceiptStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * The payment-received confirmation document — ONE per Payment
 * (20-billing-and-payments-domain.md §90–§94; ADR-191 B5-C).
 *
 * `RCT-%08d` minted at `RecordPayment`. Reprint is a read (§93 — never a new
 * row). When the payment is reversed the receipt is FLAGGED `REVERSED`, never
 * deleted (§94 — the reversal stays traceable). Snapshots the payment's
 * amount / method. Records no event.
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
 * @property string $payment_id
 * @property string $receipt_number
 * @property string $method
 * @property string $amount
 * @property string $currency
 * @property string $status
 * @property CarbonImmutable $issued_at
 * @property string $issued_by_user_id
 * @property CarbonImmutable|null $reversed_at
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class Receipt extends AggregateRoot
{
    /** @use HasFactory<ReceiptFactory> */
    use HasFactory;

    public const STATUS_ISSUED = ReceiptStatus::ISSUED;

    public const STATUS_REVERSED = ReceiptStatus::REVERSED;

    protected $table = 'bill_receipts';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'financial_account_id', 'patient_id', 'payment_id',
        'receipt_number', 'method', 'amount', 'currency', 'status',
        'issued_at', 'issued_by_user_id', 'reversed_at',
        'occurred_at', 'recorded_at', 'version',
    ];

    protected static function newFactory(): ReceiptFactory
    {
        return ReceiptFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'issued_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @param  numeric-string  $amount
     */
    public static function issue(
        string $id,
        string $tenantId,
        string $facilityId,
        string $financialAccountId,
        string $patientId,
        string $paymentId,
        string $receiptNumber,
        string $method,
        string $amount,
        string $currency,
        string $issuedByUserId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'financial_account_id' => $financialAccountId,
            'patient_id' => $patientId,
            'payment_id' => $paymentId,
            'receipt_number' => $receiptNumber,
            'method' => $method,
            'amount' => $amount,
            'currency' => $currency,
            'status' => self::STATUS_ISSUED,
            'issued_at' => $occurredAt,
            'issued_by_user_id' => $issuedByUserId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /** The payment was reversed — flag, never delete (§94). Idempotent. */
    public function markReversed(): void
    {
        if ($this->status === self::STATUS_REVERSED) {
            return;
        }

        $this->status = self::STATUS_REVERSED;
        $this->reversed_at = CarbonImmutable::now();
    }
}
