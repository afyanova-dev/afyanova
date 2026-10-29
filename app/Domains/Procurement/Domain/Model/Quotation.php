<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Database\Factories\QuotationFactory;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A supplier's quotation against an RFQ (19-procurement-domain.md §32, §33,
 * §233, §246; ADR-173 D-C / D-D).
 *
 * ONE per (RFQ, supplier). Lifecycle: RECEIVED (revisable while the RFQ is
 * ISSUED) → SUBMITTED (locked at `CloseRfq`) → AWARDED | NOT_AWARDED (at
 * `AwardRfq`); RECEIVED → WITHDRAWN. Records NO event (ADR-173 D-I). Lines live
 * on `pcr_quotation_lines`; the revision history on `pcr_quotation_revisions`
 * (append-only). Quotations are PROCUREMENT-STAFF-entered (`received_by_user_id`).
 *
 * @domain      Procurement
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
 * @property string $quotation_number
 * @property string $rfq_id
 * @property string $supplier_id
 * @property string|null $supplier_quote_ref
 * @property string $currency
 * @property CarbonImmutable|null $validity_date
 * @property int|null $lead_time_days
 * @property string|null $payment_terms
 * @property string|null $delivery_terms
 * @property string|null $notes
 * @property string $status
 * @property int $revision_count
 * @property string $received_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property int $version
 */
final class Quotation extends AggregateRoot
{
    /** @use HasFactory<QuotationFactory> */
    use HasFactory;

    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_SUBMITTED = 'SUBMITTED';

    public const STATUS_AWARDED = 'AWARDED';

    public const STATUS_NOT_AWARDED = 'NOT_AWARDED';

    public const STATUS_WITHDRAWN = 'WITHDRAWN';

    protected $table = 'pcr_quotations';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'quotation_number', 'rfq_id', 'supplier_id', 'supplier_quote_ref',
        'currency', 'validity_date', 'lead_time_days', 'payment_terms', 'delivery_terms', 'notes',
        'status', 'revision_count',
        'received_by_user_id', 'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): QuotationFactory
    {
        return QuotationFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'validity_date' => 'immutable_date',
            'lead_time_days' => 'integer',
            'revision_count' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function create(
        string $id,
        string $tenantId,
        string $facilityId,
        string $quotationNumber,
        string $rfqId,
        string $supplierId,
        string $receivedByUserId,
        string $currency,
        ?string $supplierQuoteRef,
        ?string $validityDate,
        ?int $leadTimeDays,
        ?string $paymentTerms,
        ?string $deliveryTerms,
        ?string $notes,
        ?CarbonImmutable $occurredAt = null,
    ): self {
        $now = CarbonImmutable::now();

        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'quotation_number' => $quotationNumber,
            'rfq_id' => $rfqId,
            'supplier_id' => $supplierId,
            'supplier_quote_ref' => $supplierQuoteRef !== null && trim($supplierQuoteRef) !== '' ? trim($supplierQuoteRef) : null,
            'currency' => strtoupper(trim($currency)),
            'validity_date' => $validityDate !== null && trim($validityDate) !== '' ? CarbonImmutable::parse($validityDate) : null,
            'lead_time_days' => $leadTimeDays,
            'payment_terms' => $paymentTerms !== null && trim($paymentTerms) !== '' ? trim($paymentTerms) : null,
            'delivery_terms' => $deliveryTerms !== null && trim($deliveryTerms) !== '' ? trim($deliveryTerms) : null,
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            'status' => self::STATUS_RECEIVED,
            'revision_count' => 0,
            'received_by_user_id' => $receivedByUserId,
            'occurred_at' => $occurredAt ?? $now,
            'recorded_at' => $now,
        ]);
    }

    /** Record a pre-close revision — always dirties the row so `version` bumps (T.2 lesson). */
    public function recordRevision(): void
    {
        $this->revision_count = (int) $this->revision_count + 1;
    }

    public function markSubmitted(): void
    {
        $this->status = self::STATUS_SUBMITTED;
    }

    public function markAwarded(): void
    {
        $this->status = self::STATUS_AWARDED;
    }

    public function markNotAwarded(): void
    {
        $this->status = self::STATUS_NOT_AWARDED;
    }

    public function markWithdrawn(): void
    {
        $this->status = self::STATUS_WITHDRAWN;
    }
}
