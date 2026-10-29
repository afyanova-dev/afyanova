<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Model;

use App\Domains\Billing\Database\Factories\PaymentAllocationFactory;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One allocation of a Payment against an ISSUED Invoice
 * (20-billing-and-payments-domain.md §65 / §294; ADR-191 B5-A).
 *
 * Allocations post NO ledger entry (B5-A / ADR-190 INV-1 analogue) — the
 * Payment already posted its `PAYMENT` / `CREDIT` for the full amount. This row
 * is the SUB-LEDGER that says which obligation the money settled; the invoice
 * paid-status is reader-derived from it (ADR-190 D1). `allocation_active`
 * toggled (the Bill.4 INV-3 pattern — `UnallocatePayment` / a payment reversal
 * deactivates; the historical row is preserved). ONE live allocation per
 * (payment, invoice) — the partial unique index. Records no event.
 *
 * @domain      Billing
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    not-applicable
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $payment_id
 * @property string $invoice_id
 * @property string $financial_account_id
 * @property string $amount
 * @property bool $allocation_active
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class PaymentAllocation extends AggregateRoot
{
    /** @use HasFactory<PaymentAllocationFactory> */
    use HasFactory;

    protected $table = 'bill_payment_allocations';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'payment_id', 'invoice_id', 'financial_account_id',
        'amount', 'allocation_active',
        'occurred_at', 'recorded_at', 'version',
    ];

    protected static function newFactory(): PaymentAllocationFactory
    {
        return PaymentAllocationFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'allocation_active' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @param  numeric-string  $amount  scale-2, > 0
     */
    public static function create(
        string $id,
        string $tenantId,
        string $paymentId,
        string $invoiceId,
        string $financialAccountId,
        string $amount,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'payment_id' => $paymentId,
            'invoice_id' => $invoiceId,
            'financial_account_id' => $financialAccountId,
            'amount' => $amount,
            'allocation_active' => true,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    public function deactivate(): void
    {
        $this->allocation_active = false;
    }
}
