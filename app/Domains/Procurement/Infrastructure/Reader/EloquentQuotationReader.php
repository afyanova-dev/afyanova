<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Reader;

use App\Domains\Procurement\Contracts\Dto\QuotationComparison;
use App\Domains\Procurement\Contracts\Dto\QuotationComparisonRow;
use App\Domains\Procurement\Contracts\Dto\QuotationLineSummary;
use App\Domains\Procurement\Contracts\Dto\QuotationRevisionSummary;
use App\Domains\Procurement\Contracts\Dto\QuotationSummary;
use App\Domains\Procurement\Domain\Model\Quotation;
use App\Domains\Procurement\Domain\Model\QuotationLine;
use App\Domains\Procurement\Domain\Model\QuotationRevision;
use App\Domains\Procurement\Domain\Model\RfqLine;
use App\Domains\Procurement\Domain\Money;
use App\Domains\Procurement\Domain\QuotationReader;

final class EloquentQuotationReader implements QuotationReader
{
    public function listForRfq(string $rfqId): array
    {
        return array_values(
            Quotation::query()
                ->where('rfq_id', $rfqId)
                ->orderBy('quotation_number')
                ->get()
                ->map(fn (Quotation $q): QuotationSummary => $this->toSummary($q))
                ->all()
        );
    }

    public function compareForRfq(string $rfqId): QuotationComparison
    {
        $quotations = Quotation::query()
            ->where('rfq_id', $rfqId)
            ->whereIn('status', [Quotation::STATUS_RECEIVED, Quotation::STATUS_SUBMITTED, Quotation::STATUS_AWARDED, Quotation::STATUS_NOT_AWARDED])
            ->orderBy('quotation_number')
            ->get();

        /** @var array<string, array<string, array{unitPrice: string, offeredQty: string, lineTotal: string}>> $byLine */
        $byLine = [];
        $quotationMeta = [];

        foreach ($quotations as $q) {
            $total = '0';
            $lines = QuotationLine::query()->where('quotation_id', $q->id)->get();
            foreach ($lines as $line) {
                $lineTotal = $line->lineTotal();
                $total = Money::add($total, $lineTotal);
                $byLine[(string) $line->rfq_line_id][(string) $q->id] = [
                    'unitPrice' => (string) $line->unit_price,
                    'offeredQty' => (string) $line->offered_qty,
                    'lineTotal' => $lineTotal,
                ];
            }

            $quotationMeta[] = [
                'quotationId' => (string) $q->id,
                'supplierId' => (string) $q->supplier_id,
                'quotationNumber' => (string) $q->quotation_number,
                'currency' => (string) $q->currency,
                'leadTimeDays' => $q->lead_time_days !== null ? (int) $q->lead_time_days : null,
                'quotationTotal' => $total === '0' ? '0.00' : $total,
            ];
        }

        $rows = RfqLine::query()
            ->where('rfq_id', $rfqId)
            ->orderBy('line_no')
            ->get()
            ->map(fn (RfqLine $line): QuotationComparisonRow => new QuotationComparisonRow(
                rfqLineId: (string) $line->id,
                lineNo: (int) $line->line_no,
                itemCode: $line->item_code,
                itemName: $line->item_name,
                description: $line->description,
                requestedQty: (string) $line->quantity,
                uom: (string) $line->uom,
                cells: $byLine[(string) $line->id] ?? [],
            ))
            ->all();

        return new QuotationComparison(
            rfqId: $rfqId,
            quotations: $quotationMeta,
            rows: array_values($rows),
        );
    }

    private function toSummary(Quotation $q): QuotationSummary
    {
        $total = '0';
        $lines = QuotationLine::query()
            ->where('quotation_id', $q->id)
            ->orderBy('line_no')
            ->get()
            ->map(function (QuotationLine $line) use (&$total): QuotationLineSummary {
                $lineTotal = $line->lineTotal();
                $total = Money::add($total, $lineTotal);

                return new QuotationLineSummary(
                    lineId: (string) $line->id,
                    lineNo: (int) $line->line_no,
                    rfqLineId: (string) $line->rfq_line_id,
                    unitPrice: (string) $line->unit_price,
                    offeredQty: (string) $line->offered_qty,
                    taxAmount: $line->tax_amount !== null ? (string) $line->tax_amount : null,
                    discountAmount: $line->discount_amount !== null ? (string) $line->discount_amount : null,
                    lineTotal: $lineTotal,
                    lineNote: $line->line_note,
                );
            })
            ->all();

        $revisions = QuotationRevision::query()
            ->where('quotation_id', $q->id)
            ->orderBy('sequence')
            ->get()
            ->map(fn (QuotationRevision $r): QuotationRevisionSummary => new QuotationRevisionSummary(
                revisionId: (string) $r->id,
                sequence: (int) $r->sequence,
                fieldChanged: (string) $r->field_changed,
                previousValue: $r->previous_value,
                newValue: $r->new_value,
                reason: (string) $r->reason,
                revisedByUserId: (string) $r->revised_by_user_id,
                revisedAt: $r->revised_at->toIso8601String(),
            ))
            ->all();

        return new QuotationSummary(
            quotationId: (string) $q->id,
            facilityId: (string) $q->facility_id,
            quotationNumber: (string) $q->quotation_number,
            rfqId: (string) $q->rfq_id,
            supplierId: (string) $q->supplier_id,
            supplierQuoteRef: $q->supplier_quote_ref,
            currency: (string) $q->currency,
            validityDate: $q->validity_date?->toDateString(),
            leadTimeDays: $q->lead_time_days !== null ? (int) $q->lead_time_days : null,
            paymentTerms: $q->payment_terms,
            deliveryTerms: $q->delivery_terms,
            notes: $q->notes,
            status: (string) $q->status,
            revisionCount: (int) $q->revision_count,
            receivedByUserId: (string) $q->received_by_user_id,
            quotationTotal: $total === '0' ? '0.00' : $total,
            lines: array_values($lines),
            revisions: array_values($revisions),
            createdAt: $q->created_at?->toIso8601String(),
        );
    }
}
