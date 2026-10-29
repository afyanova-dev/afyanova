<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Exception\QuotationAlreadyExists;
use App\Domains\Procurement\Domain\Exception\QuotationLineInvalid;
use App\Domains\Procurement\Domain\Exception\SupplierNotInvited;
use App\Domains\Procurement\Domain\Model\Quotation;
use App\Domains\Procurement\Domain\Model\QuotationLine;
use App\Domains\Procurement\Domain\Model\RfqInvitation;
use App\Domains\Procurement\Domain\Model\RfqLine;
use App\Domains\Procurement\Domain\Money;
use App\Domains\Procurement\Domain\QuotationRepository;
use App\Domains\Procurement\Domain\RfqRepository;
use App\Domains\Procurement\Domain\Service\RfqLifecycle;
use App\Domains\Procurement\Infrastructure\Sequence\QuotationNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Orchestrates `RecordQuotation` (ADR-173 D-D).
 *
 * Flow: load the RFQ `FOR UPDATE` → it must be ISSUED → the supplier must be
 * INVITED → no existing quotation for that (RFQ, supplier) → every line cites a
 * real RFQ line → allocate `QUO-%08d` → create RECEIVED → save → write
 * `pcr_quotation_lines` (one txn).
 *
 * `RfqRepository` is the first repository parameter → `RfqPolicy::record` at the
 * RFQ's facility (`procurement.quotations.record`).
 */
final class RecordQuotationHandler
{
    public function __construct(
        private readonly RfqRepository $rfqs,
        private readonly QuotationRepository $quotations,
        private readonly RfqLifecycle $lifecycle,
        private readonly QuotationNumberSequence $quotationNumbers,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RecordQuotation $command): void
    {
        $rfq = $this->rfqs->getByIdForUpdate($command->rfqId);

        $this->lifecycle->assertOpenForQuotation((string) $rfq->id, (string) $rfq->status);

        $invited = RfqInvitation::query()
            ->where('rfq_id', $rfq->id)
            ->where('supplier_id', $command->supplierId)
            ->exists();
        if (! $invited) {
            throw SupplierNotInvited::forRfq((string) $rfq->id, $command->supplierId);
        }

        if ($this->quotations->findByRfqAndSupplier((string) $rfq->id, $command->supplierId) !== null) {
            throw QuotationAlreadyExists::forRfqAndSupplier((string) $rfq->id, $command->supplierId);
        }

        /** @var array<string, true> $rfqLineIds */
        $rfqLineIds = RfqLine::query()
            ->where('rfq_id', $rfq->id)
            ->pluck('id')
            ->mapWithKeys(static fn ($id): array => [(string) $id => true])
            ->all();

        foreach ($command->lines as $index => $line) {
            if (! isset($rfqLineIds[(string) $line['rfqLineId']])) {
                throw QuotationLineInvalid::notOnRfq($index + 1);
            }
        }

        $tenantId = $this->context->tenantId();

        $quotation = Quotation::create(
            id: $command->quotationId,
            tenantId: $tenantId,
            facilityId: (string) $rfq->facility_id,
            quotationNumber: $this->quotationNumbers->next($tenantId),
            rfqId: (string) $rfq->id,
            supplierId: $command->supplierId,
            receivedByUserId: $command->actorId,
            currency: $command->currency,
            supplierQuoteRef: $command->supplierQuoteRef,
            validityDate: $command->validityDate,
            leadTimeDays: $command->leadTimeDays,
            paymentTerms: $command->paymentTerms,
            deliveryTerms: $command->deliveryTerms,
            notes: $command->notes,
        );

        $this->quotations->save($quotation);

        foreach ($command->lines as $index => $line) {
            QuotationLine::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'facility_id' => (string) $rfq->facility_id,
                'quotation_id' => $command->quotationId,
                'rfq_line_id' => (string) $line['rfqLineId'],
                'line_no' => $index + 1,
                'unit_price' => Money::of((string) $line['unitPrice']),
                'offered_qty' => (string) $line['offeredQty'],
                'tax_amount' => isset($line['taxAmount']) && is_numeric((string) $line['taxAmount']) ? Money::of((string) $line['taxAmount']) : null,
                'discount_amount' => isset($line['discountAmount']) && is_numeric((string) $line['discountAmount']) ? Money::of((string) $line['discountAmount']) : null,
                'line_note' => isset($line['lineNote']) && trim((string) $line['lineNote']) !== '' ? trim((string) $line['lineNote']) : null,
            ]);
        }
    }
}
