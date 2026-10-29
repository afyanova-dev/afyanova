<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Model;

use App\Domains\Billing\Contracts\Event\InvoiceIssued;
use App\Domains\Billing\Contracts\Event\InvoiceVoided;
use App\Domains\Billing\Database\Factories\InvoiceFactory;
use App\Domains\Billing\Domain\Exception\IllegalInvoiceTransition;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\ValueObject\InvoicePayerType;
use App\Domains\Billing\Domain\ValueObject\InvoiceStatus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * A facility financial document that GROUPS POSTED charges into a request for
 * payment (20-billing-and-payments-domain.md §34 / §35 / §92 / §287 / §296;
 * ADR-190 D-A).
 *
 * ADR-190 invariants: posts ZERO ledger entries (INV-1); never mutates
 * `bill_charges` (INV-2); ISSUED lines + `net` immutable (INV-4);
 * `invoice_number` minted only at issue (INV-6); void preserves history
 * (INV-7). `discount` / `tax` are `'0.00'` in Bill.4.
 *
 * ADR-201 amends INV-5: `payer_type` is `SELF_PAY | INSURANCE`. An estimated
 * payer/patient split ({@see self::applyCoverage()}, via `SetInvoiceCoverage`)
 * is ATTRIBUTION on this row — it still posts ZERO ledger entries (INV-1 held)
 * and never touches `bill_charges` (INV-2 held). It freezes at ISSUE with
 * `net`. The ACTUAL split (payer remittance) is the Insurance domain's Ins.8.
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
 * @property string|null $invoice_number
 * @property string $payer_type
 * @property string $status
 * @property string $gross
 * @property string $discount
 * @property string $tax
 * @property string $net
 * @property string $currency
 * @property string|null $payer_id
 * @property string|null $member_coverage_id
 * @property string|null $coverage_plan_id
 * @property string $payer_responsibility
 * @property string $patient_responsibility
 * @property string|null $coverage_status
 * @property string|null $coverage_source
 * @property string|null $coverage_confidence
 * @property CarbonImmutable|null $coverage_evaluated_at
 * @property CarbonImmutable|null $issued_at
 * @property string|null $issued_by_user_id
 * @property CarbonImmutable|null $voided_at
 * @property string|null $voided_by_user_id
 * @property string|null $void_reason_code
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class Invoice extends AggregateRoot
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    public const STATUS_DRAFT = InvoiceStatus::DRAFT;

    public const STATUS_ISSUED = InvoiceStatus::ISSUED;

    public const STATUS_VOIDED = InvoiceStatus::VOIDED;

    public const PAYER_SELF_PAY = InvoicePayerType::SELF_PAY;

    public const PAYER_INSURANCE = InvoicePayerType::INSURANCE;

    protected $table = 'bill_invoices';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'financial_account_id', 'patient_id',
        'invoice_number', 'payer_type', 'status',
        'gross', 'discount', 'tax', 'net', 'currency',
        'payer_id', 'member_coverage_id', 'coverage_plan_id',
        'payer_responsibility', 'patient_responsibility',
        'coverage_status', 'coverage_source', 'coverage_confidence', 'coverage_evaluated_at',
        'issued_at', 'issued_by_user_id',
        'voided_at', 'voided_by_user_id', 'void_reason_code',
        'occurred_at', 'recorded_at', 'version',
    ];

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gross' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax' => 'decimal:4',
            'net' => 'decimal:4',
            'payer_responsibility' => 'decimal:4',
            'patient_responsibility' => 'decimal:4',
            'coverage_evaluated_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function open(
        string $id,
        string $tenantId,
        string $facilityId,
        string $financialAccountId,
        string $patientId,
        string $currency,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'financial_account_id' => $financialAccountId,
            'patient_id' => $patientId,
            'invoice_number' => null,
            'payer_type' => self::PAYER_SELF_PAY,
            'status' => self::STATUS_DRAFT,
            'gross' => '0.00',
            'discount' => '0.00',
            'tax' => '0.00',
            'net' => '0.00',
            'currency' => $currency,
            'payer_id' => null,
            'member_coverage_id' => null,
            'coverage_plan_id' => null,
            'payer_responsibility' => '0.00',
            'patient_responsibility' => '0.00',
            'coverage_status' => null,
            'coverage_source' => null,
            'coverage_confidence' => null,
            'coverage_evaluated_at' => null,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /**
     * DRAFT only — `net := gross − discount + tax` (discount / tax are `0.00`).
     *
     * @param  numeric-string  $gross
     */
    public function recomputeTotals(string $gross): void
    {
        $this->assertDraft('recomputeTotals');

        $this->gross = Money::of($gross);
        $this->net = Money::add(Money::subtract($this->gross, (string) $this->discount), (string) $this->tax);
    }

    /**
     * DRAFT only — attach an Insurance coverage and its ESTIMATED payer/patient
     * split (ADR-201 D-C). The estimate is attribution on this row; it posts no
     * ledger entry (INV-1). Frozen at ISSUE alongside `net`.
     *
     * @param  numeric-string  $payerResponsibility
     * @param  numeric-string  $patientResponsibility
     */
    public function applyCoverage(
        string $payerId,
        string $memberCoverageId,
        string $coveragePlanId,
        string $payerResponsibility,
        string $patientResponsibility,
        string $coverageStatus,
        string $coverageSource,
        string $coverageConfidence,
        CarbonImmutable $evaluatedAt,
    ): void {
        $this->assertDraft('SetInvoiceCoverage');

        $this->payer_type = self::PAYER_INSURANCE;
        $this->payer_id = $payerId;
        $this->member_coverage_id = $memberCoverageId;
        $this->coverage_plan_id = $coveragePlanId;
        $this->payer_responsibility = Money::of($payerResponsibility);
        $this->patient_responsibility = Money::of($patientResponsibility);
        $this->coverage_status = $coverageStatus;
        $this->coverage_source = $coverageSource;
        $this->coverage_confidence = $coverageConfidence;
        $this->coverage_evaluated_at = $evaluatedAt;
    }

    /** DRAFT only — revert to SELF_PAY (ADR-201 D-C). Idempotent. */
    public function clearCoverage(): void
    {
        if ($this->payer_type === self::PAYER_SELF_PAY && $this->payer_id === null) {
            return;
        }

        $this->assertDraft('SetInvoiceCoverage');

        $this->payer_type = self::PAYER_SELF_PAY;
        $this->payer_id = null;
        $this->member_coverage_id = null;
        $this->coverage_plan_id = null;
        $this->payer_responsibility = '0.00';
        $this->patient_responsibility = '0.00';
        $this->coverage_status = null;
        $this->coverage_source = null;
        $this->coverage_confidence = null;
        $this->coverage_evaluated_at = null;
    }

    /** DRAFT → ISSUED — mints the number, freezes `net`, records `InvoiceIssued`. */
    public function issue(string $invoiceNumber, string $issuedByUserId): void
    {
        $this->assertDraft('IssueInvoice');

        $now = CarbonImmutable::now();
        $this->status = self::STATUS_ISSUED;
        $this->invoice_number = $invoiceNumber;
        $this->issued_at = $now;
        $this->issued_by_user_id = $issuedByUserId;

        $this->recordThat(new InvoiceIssued(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            financialAccountId: (string) $this->financial_account_id,
            invoiceId: (string) $this->id,
            invoiceNumber: $invoiceNumber,
            net: Money::of((string) $this->net),
            currency: (string) $this->currency,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /** {DRAFT | ISSUED} → VOIDED — records `InvoiceVoided`. History preserved (INV-7). */
    public function void(string $voidedByUserId, string $reasonCode): void
    {
        if (! in_array($this->status, InvoiceStatus::VOIDABLE, true)) {
            throw IllegalInvoiceTransition::fromStatus('VoidInvoice', (string) $this->status);
        }

        $now = CarbonImmutable::now();
        $this->status = self::STATUS_VOIDED;
        $this->voided_at = $now;
        $this->voided_by_user_id = $voidedByUserId;
        $this->void_reason_code = $reasonCode;

        $this->recordThat(new InvoiceVoided(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            invoiceId: (string) $this->id,
            voidReasonCode: $reasonCode,
            voidedByUserId: $voidedByUserId,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    private function assertDraft(string $action): void
    {
        if ($this->status !== self::STATUS_DRAFT) {
            throw IllegalInvoiceTransition::fromStatus($action, (string) $this->status);
        }
    }
}
