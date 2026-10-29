<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Reader;

use App\Domains\Procurement\Contracts\Dto\RfqAwardSummary;
use App\Domains\Procurement\Contracts\Dto\RfqInvitationSummary;
use App\Domains\Procurement\Contracts\Dto\RfqLineSummary;
use App\Domains\Procurement\Contracts\Dto\RfqSummary;
use App\Domains\Procurement\Domain\Model\Quotation;
use App\Domains\Procurement\Domain\Model\Rfq;
use App\Domains\Procurement\Domain\Model\RfqAward;
use App\Domains\Procurement\Domain\Model\RfqInvitation;
use App\Domains\Procurement\Domain\Model\RfqLine;
use App\Domains\Procurement\Domain\Quantity;
use App\Domains\Procurement\Domain\RfqReader;
use App\Shared\Domain\NotFound;

final class EloquentRfqReader implements RfqReader
{
    public function summary(string $rfqId): RfqSummary
    {
        $rfq = Rfq::query()->whereKey($rfqId)->first()
            ?? throw NotFound::aggregate(Rfq::class, $rfqId);

        return $this->toSummary($rfq);
    }

    public function facilityOf(string $rfqId): string
    {
        $facilityId = Rfq::query()->whereKey($rfqId)->value('facility_id');

        if ($facilityId === null) {
            throw NotFound::aggregate(Rfq::class, $rfqId);
        }

        return (string) $facilityId;
    }

    public function searchForFacility(string $facilityId, ?string $status = null): array
    {
        $query = Rfq::query()->where('facility_id', $facilityId);

        if ($status !== null) {
            $query->where('status', strtoupper(trim($status)));
        }

        return array_values(
            $query->orderByDesc('recorded_at')->orderBy('id')->get()
                ->map(fn (Rfq $rfq): RfqSummary => $this->toSummary($rfq))
                ->all()
        );
    }

    private function toSummary(Rfq $rfq): RfqSummary
    {
        /** @var array<string, string> $awardedPerLine */
        $awardedPerLine = [];

        $awards = RfqAward::query()
            ->where('rfq_id', $rfq->id)
            ->orderBy('rfq_line_id')
            ->orderBy('id')
            ->get()
            ->map(function (RfqAward $a) use (&$awardedPerLine): RfqAwardSummary {
                $awardedPerLine[(string) $a->rfq_line_id] = Quantity::add(
                    $awardedPerLine[(string) $a->rfq_line_id] ?? '0',
                    (string) $a->awarded_qty,
                );

                return new RfqAwardSummary(
                    awardId: (string) $a->id,
                    rfqLineId: (string) $a->rfq_line_id,
                    quotationId: (string) $a->quotation_id,
                    supplierId: (string) $a->supplier_id,
                    awardedQty: (string) $a->awarded_qty,
                    reason: (string) $a->reason,
                    decidedByUserId: (string) $a->decided_by_user_id,
                    decidedAt: $a->decided_at->toIso8601String(),
                );
            })
            ->all();

        $lines = RfqLine::query()
            ->where('rfq_id', $rfq->id)
            ->orderBy('line_no')
            ->get()
            ->map(function (RfqLine $line) use ($awardedPerLine): RfqLineSummary {
                $awarded = $awardedPerLine[(string) $line->id] ?? '0';
                $outstanding = Quantity::subtract((string) $line->quantity, $awarded);

                return new RfqLineSummary(
                    lineId: (string) $line->id,
                    lineNo: (int) $line->line_no,
                    lineType: (string) $line->line_type,
                    itemRef: $line->item_ref !== null ? (string) $line->item_ref : null,
                    itemCode: $line->item_code,
                    itemName: $line->item_name,
                    description: $line->description,
                    quantity: (string) $line->quantity,
                    uom: (string) $line->uom,
                    requiredDate: $line->required_date?->toDateString(),
                    awardedQty: Quantity::of($awarded),
                    outstandingQty: $outstanding,
                );
            })
            ->all();

        /** @var list<string> $quotedSupplierIds */
        $quotedSupplierIds = Quotation::query()
            ->where('rfq_id', $rfq->id)
            ->pluck('supplier_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $invitations = RfqInvitation::query()
            ->where('rfq_id', $rfq->id)
            ->orderBy('invited_at')
            ->orderBy('id')
            ->get()
            ->map(fn (RfqInvitation $i): RfqInvitationSummary => new RfqInvitationSummary(
                supplierId: (string) $i->supplier_id,
                invitedAt: $i->invited_at->toIso8601String(),
                hasQuotation: in_array((string) $i->supplier_id, $quotedSupplierIds, true),
            ))
            ->all();

        return new RfqSummary(
            rfqId: (string) $rfq->id,
            facilityId: (string) $rfq->facility_id,
            rfqNumber: (string) $rfq->rfq_number,
            requisitionId: $rfq->requisition_id !== null ? (string) $rfq->requisition_id : null,
            title: (string) $rfq->title,
            reason: $rfq->reason,
            requiredDate: $rfq->required_date?->toDateString(),
            closingDate: $rfq->closing_date?->toDateString(),
            terms: $rfq->terms,
            status: (string) $rfq->status,
            cancellationReason: $rfq->cancellation_reason,
            awardedByUserId: $rfq->awarded_by_user_id !== null ? (string) $rfq->awarded_by_user_id : null,
            awardedAt: $rfq->awarded_at?->toIso8601String(),
            createdByUserId: (string) $rfq->created_by_user_id,
            quotationCount: count($quotedSupplierIds),
            lines: array_values($lines),
            invitations: array_values($invitations),
            awards: array_values($awards),
            createdAt: $rfq->created_at?->toIso8601String(),
            requiresEvaluation: $rfq->requires_evaluation !== null ? (bool) $rfq->requires_evaluation : null,
        );
    }
}
