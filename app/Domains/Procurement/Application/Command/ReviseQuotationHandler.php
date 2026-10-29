<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Exception\QuotationLineInvalid;
use App\Domains\Procurement\Domain\Model\Quotation;
use App\Domains\Procurement\Domain\Model\QuotationLine;
use App\Domains\Procurement\Domain\Model\QuotationRevision;
use App\Domains\Procurement\Domain\Money;
use App\Domains\Procurement\Domain\QuotationRepository;
use App\Domains\Procurement\Domain\RfqRepository;
use App\Domains\Procurement\Domain\Service\QuotationLifecycle;
use App\Domains\Procurement\Domain\Service\RfqLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Orchestrates `ReviseQuotation` (ADR-173 D-D / §233).
 *
 * Legal only while the quotation is RECEIVED AND its RFQ is ISSUED. One
 * structured field changes (a `pcr_quotation_lines` field or a `pcr_quotations`
 * header field); `pcr_quotation_revisions` gets one append-only row (sequence =
 * `revision_count` + 1); `revision_count` bumps so the row is always dirty (T.2
 * lesson) and `version` advances. The plain-child mutation is the
 * `AmendPurchaseOrderHandler` precedent.
 *
 * `QuotationRepository` is the first repository parameter → `QuotationPolicy::record`.
 */
final class ReviseQuotationHandler
{
    public function __construct(
        private readonly QuotationRepository $quotations,
        private readonly RfqRepository $rfqs,
        private readonly QuotationLifecycle $lifecycle,
        private readonly RfqLifecycle $rfqLifecycle,
    ) {}

    public function __invoke(ReviseQuotation $command): void
    {
        $quotation = $this->quotations->getByIdForUpdate($command->quotationId);

        $this->lifecycle->assertCanRevise((string) $quotation->status);

        $rfq = $this->rfqs->getById((string) $quotation->rfq_id);
        $this->rfqLifecycle->assertOpenForQuotation((string) $rfq->id, (string) $rfq->status);

        $sequence = (int) $quotation->revision_count + 1;
        $newValue = $this->blankToNull($command->newValue);

        if ($command->isLineField()) {
            [$previous, $lineId] = $this->applyToLine($command, $newValue);
        } else {
            $previous = $this->applyToHeader($quotation, $command->field, $newValue);
            $lineId = null;
        }

        $quotation->recordRevision();
        $this->quotations->save($quotation);

        QuotationRevision::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $quotation->tenant_id,
            'facility_id' => (string) $quotation->facility_id,
            'quotation_id' => (string) $quotation->id,
            'sequence' => $sequence,
            'field_changed' => $command->field.($lineId !== null ? ' (line '.$lineId.')' : ''),
            'previous_value' => $previous,
            'new_value' => $newValue,
            'reason' => trim($command->reason),
            'revised_by_user_id' => $command->actorId,
            'revised_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function applyToLine(ReviseQuotation $command, ?string $newValue): array
    {
        /** @var QuotationLine|null $line */
        $line = QuotationLine::query()
            ->where('quotation_id', $command->quotationId)
            ->whereKey($command->quotationLineId)
            ->first();

        if ($line === null) {
            throw QuotationLineInvalid::notOnQuotation((string) $command->quotationLineId);
        }

        $previous = match ($command->field) {
            'unit_price' => (string) $line->unit_price,
            'offered_qty' => (string) $line->offered_qty,
            'tax_amount' => $line->tax_amount !== null ? (string) $line->tax_amount : null,
            default => $line->discount_amount !== null ? (string) $line->discount_amount : null,
        };

        if ($command->field === 'unit_price') {
            $line->unit_price = Money::of((string) $newValue);
        } elseif ($command->field === 'offered_qty') {
            $line->offered_qty = (string) $newValue; // validated positive-numeric by the ctor
        } elseif ($command->field === 'tax_amount') {
            $line->tax_amount = $newValue !== null ? Money::of($newValue) : null;
        } else {
            $line->discount_amount = $newValue !== null ? Money::of($newValue) : null;
        }

        $line->save();

        return [$previous, (string) $line->id];
    }

    private function applyToHeader(Quotation $quotation, string $field, ?string $newValue): ?string
    {
        if ($field === 'lead_time_days') {
            $previous = $quotation->lead_time_days !== null ? (string) $quotation->lead_time_days : null;
            $quotation->lead_time_days = $newValue !== null ? (int) $newValue : null;

            return $previous;
        }

        if ($field === 'validity_date') {
            $previous = $quotation->validity_date?->toDateString();
            $quotation->validity_date = $newValue !== null ? CarbonImmutable::parse($newValue) : null;

            return $previous;
        }

        $previous = $field === 'payment_terms' ? $quotation->payment_terms : $quotation->delivery_terms;
        if ($field === 'payment_terms') {
            $quotation->payment_terms = $newValue;
        } else {
            $quotation->delivery_terms = $newValue;
        }

        return $previous;
    }

    private function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
