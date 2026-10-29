<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Model;

use App\Domains\Billing\Database\Factories\InvoiceLineFactory;
use App\Domains\Billing\Domain\Money;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One POSTED charge on one invoice (20-billing-and-payments-domain.md §35 / §88;
 * ADR-190 D-B).
 *
 * ADR-190 INV-3: `line_active = true` CLAIMS the charge (unique per charge);
 * `deactivate()` (on `RemoveInvoiceLine` for a DRAFT, or on `VoidInvoice`)
 * releases it — the historical row is PRESERVED (INV-7). INV-2: `bill_charges`
 * is never touched; this row SNAPSHOTS the charge's description / quantity /
 * unit price / amount and keeps `charge_id`. Records no event.
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
 * @property string $invoice_id
 * @property string $charge_id
 * @property string $description
 * @property string $billing_service_code
 * @property string $quantity
 * @property string $unit_price
 * @property string $amount
 * @property bool $line_active
 * @property string|null $service_category
 * @property string|null $payer_responsibility
 * @property string|null $patient_responsibility
 * @property string|null $coverage_status
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class InvoiceLine extends AggregateRoot
{
    /** @use HasFactory<InvoiceLineFactory> */
    use HasFactory;

    protected $table = 'bill_invoice_lines';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'invoice_id', 'charge_id',
        'description', 'billing_service_code', 'quantity', 'unit_price', 'amount',
        'line_active',
        'service_category', 'payer_responsibility', 'patient_responsibility', 'coverage_status',
        'occurred_at', 'recorded_at', 'version',
    ];

    protected static function newFactory(): InvoiceLineFactory
    {
        return InvoiceLineFactory::new();
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
            'line_active' => 'boolean',
            'payer_responsibility' => 'decimal:4',
            'patient_responsibility' => 'decimal:4',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @param  numeric-string  $quantity
     * @param  numeric-string  $unitPrice
     * @param  numeric-string  $amount
     */
    public static function add(
        string $id,
        string $tenantId,
        string $invoiceId,
        string $chargeId,
        string $description,
        string $billingServiceCode,
        string $quantity,
        string $unitPrice,
        string $amount,
        ?string $serviceCategory,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'invoice_id' => $invoiceId,
            'charge_id' => $chargeId,
            'description' => $description,
            'billing_service_code' => $billingServiceCode,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $amount,
            'line_active' => true,
            'service_category' => $serviceCategory,
            'payer_responsibility' => null,
            'patient_responsibility' => null,
            'coverage_status' => null,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    public function deactivate(): void
    {
        $this->line_active = false;
    }

    /**
     * Store the ESTIMATED coverage split for this line (ADR-201 D-C). Set on
     * `SetInvoiceCoverage`; the invoice is DRAFT and freezes at ISSUE.
     *
     * @param  numeric-string  $payerResponsibility
     * @param  numeric-string  $patientResponsibility
     */
    public function applyCoverage(string $payerResponsibility, string $patientResponsibility, string $coverageStatus): void
    {
        $this->payer_responsibility = Money::of($payerResponsibility);
        $this->patient_responsibility = Money::of($patientResponsibility);
        $this->coverage_status = $coverageStatus;
    }

    /** Drop the coverage split — the invoice reverted to SELF_PAY (ADR-201 D-C). */
    public function clearCoverage(): void
    {
        $this->payer_responsibility = null;
        $this->patient_responsibility = null;
        $this->coverage_status = null;
    }
}
