<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\EvaluationSettingsReader;
use App\Domains\Procurement\Domain\Exception\RfqAwardInvalid;
use App\Domains\Procurement\Domain\Exception\RfqEvaluationIncomplete;
use App\Domains\Procurement\Domain\Exception\SelfAwardNotPermitted;
use App\Domains\Procurement\Domain\Model\Quotation;
use App\Domains\Procurement\Domain\Model\QuotationLine;
use App\Domains\Procurement\Domain\Model\RfqAward;
use App\Domains\Procurement\Domain\Model\RfqLine;
use App\Domains\Procurement\Domain\Money;
use App\Domains\Procurement\Domain\QuotationEvaluationRepository;
use App\Domains\Procurement\Domain\QuotationRepository;
use App\Domains\Procurement\Domain\RfqRepository;
use App\Domains\Procurement\Domain\Service\QuotationLifecycle;
use App\Domains\Procurement\Domain\Service\RfqAwardValidator;
use App\Domains\Procurement\Domain\Service\RfqLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Orchestrates `AwardRfq` (ADR-173 D-D / D-E).
 *
 * Load the RFQ `FOR UPDATE` → it must be CLOSED → the actor must not be the RFQ
 * creator → assemble the RFQ-line / quotation / quotation-line data → the
 * evaluation gate (ADR-174 D-F, amended by ADR-175 D-D): SOFT for an unflagged
 * below-threshold RFQ (fires only once ≥ 1 evaluation is SUBMITTED); HARD when
 * the RFQ is flagged `requires_evaluation` or its max SUBMITTED-quotation total
 * ≥ the tenant's threshold (fires with zero evaluations) → `RfqAwardValidator`
 * (pure) → append one `pcr_rfq_awards` row per allocation
 * entry (append-only) → RFQ CLOSED → AWARDED → each locked quotation is asserted
 * SUBMITTED (`QuotationLifecycle::assertSubmittedForAward`) then transitioned →
 * AWARDED (cited by ≥ 1 award row) / NOT_AWARDED. One bus transaction.
 *
 * `RfqRepository` is the first repository parameter → `RfqPolicy::award`
 * (`procurement.awards.decide`); the evaluation repo + settings reader are last
 * so they do not change policy resolution.
 */
final class AwardRfqHandler
{
    public function __construct(
        private readonly RfqRepository $rfqs,
        private readonly QuotationRepository $quotations,
        private readonly RfqLifecycle $lifecycle,
        private readonly RfqAwardValidator $validator,
        private readonly QuotationLifecycle $quotationLifecycle,
        private readonly QuotationEvaluationRepository $evaluations,
        private readonly EvaluationSettingsReader $settings,
    ) {}

    public function __invoke(AwardRfq $command): void
    {
        $rfq = $this->rfqs->getByIdForUpdate($command->rfqId);

        $this->lifecycle->assertCanAward((string) $rfq->id, (string) $rfq->status);

        if ((string) $rfq->created_by_user_id === $command->actorId) {
            throw SelfAwardNotPermitted::forRfq((string) $rfq->id);
        }

        /** @var array<string, string> $rfqLineQuantities */
        $rfqLineQuantities = RfqLine::query()
            ->where('rfq_id', $rfq->id)
            ->pluck('quantity', 'id')
            ->mapWithKeys(static fn ($qty, $id): array => [(string) $id => (string) $qty])
            ->all();

        $submitted = array_values(array_filter(
            $this->quotations->lockAllForRfq((string) $rfq->id),
            static fn (Quotation $q): bool => (string) $q->status === Quotation::STATUS_SUBMITTED,
        ));
        $submittedIds = array_map(static fn (Quotation $q): string => (string) $q->id, $submitted);

        /** @var array<string, array<string, array{lineId: string, offeredQty: string, unitPrice: string}>> $offers */
        $offers = [];
        /** @var array<string, string> $quotationTotals */
        $quotationTotals = [];
        foreach (QuotationLine::query()->whereIn('quotation_id', $submittedIds ?: ['00000000-0000-0000-0000-000000000000'])->get() as $line) {
            $offers[(string) $line->quotation_id][(string) $line->rfq_line_id] = [
                'lineId' => (string) $line->id,
                'offeredQty' => (string) $line->offered_qty,
                'unitPrice' => (string) $line->unit_price,
            ];
            $quotationTotals[(string) $line->quotation_id] = Money::add(
                $quotationTotals[(string) $line->quotation_id] ?? '0',
                $line->lineTotal(),
            );
        }

        // The evaluation gate (ADR-174 D-F + ADR-175 D-D). It runs when
        // evaluation is REQUIRED for this RFQ — flagged, or above the tenant's
        // value threshold (the HARD gate, fires with zero evaluations) — OR when
        // an evaluation has been SUBMITTED anyway (the SOFT gate). Then EVERY
        // quotation in SUBMITTED status must carry ≥ 1 SUBMITTED evaluation
        // (the full set, none skipped). DRAFT evaluations never trigger or
        // satisfy it (no deadlock).
        $criteria = $this->settings->forTenant();
        $required = $rfq->requires_evaluation === true;
        if (! $required && $criteria->requiredAbove !== null && $quotationTotals !== []) {
            $maxTotal = '0';
            foreach ($quotationTotals as $total) {
                if (Money::gte($total, $maxTotal)) {
                    $maxTotal = $total;
                }
            }
            $required = Money::gte($maxTotal, $criteria->requiredAbove);
        }

        $evaluatedQuotationIds = $this->evaluations->evaluatedQuotationIds((string) $rfq->id);
        if ($required || $evaluatedQuotationIds !== []) {
            $covered = array_fill_keys($evaluatedQuotationIds, true);
            foreach ($submitted as $quotation) {
                if (! isset($covered[(string) $quotation->id])) {
                    throw RfqEvaluationIncomplete::quotationNotEvaluated((string) $rfq->id, (string) $quotation->id);
                }
            }
        }

        $this->validator->assertValid(
            $rfqLineQuantities,
            $submittedIds,
            array_map(
                static fn (array $byLine): array => array_map(static fn (array $o): string => $o['offeredQty'], $byLine),
                $offers,
            ),
            array_map(
                static fn (array $e): array => ['rfqLineId' => $e['rfqLineId'], 'quotationId' => $e['quotationId'], 'awardedQty' => $e['awardedQty']],
                $command->allocation,
            ),
        );

        /** @var array<string, Quotation> $quotationById */
        $quotationById = [];
        foreach ($submitted as $q) {
            $quotationById[(string) $q->id] = $q;
        }

        $now = CarbonImmutable::now();
        $awardedQuotationIds = [];

        foreach ($command->allocation as $entry) {
            $offer = $offers[$entry['quotationId']][$entry['rfqLineId']] ?? null;
            if ($offer === null) {
                throw RfqAwardInvalid::unquotedLine($entry['quotationId'], $entry['rfqLineId']);
            }
            $awardedQuotationIds[$entry['quotationId']] = true;

            RfqAward::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => (string) $rfq->tenant_id,
                'facility_id' => (string) $rfq->facility_id,
                'rfq_id' => (string) $rfq->id,
                'rfq_line_id' => $entry['rfqLineId'],
                'quotation_id' => $entry['quotationId'],
                'quotation_line_id' => $offer['lineId'],
                'supplier_id' => (string) $quotationById[$entry['quotationId']]->supplier_id,
                'awarded_qty' => $entry['awardedQty'],
                'unit_price' => $offer['unitPrice'],
                'reason' => $entry['reason'],
                'decided_by_user_id' => $command->actorId,
                'decided_at' => $now,
            ]);
        }

        $rfq->markAwarded($command->actorId);
        $this->rfqs->save($rfq);

        foreach ($submitted as $quotation) {
            $this->quotationLifecycle->assertSubmittedForAward((string) $quotation->status);

            if (isset($awardedQuotationIds[(string) $quotation->id])) {
                $quotation->markAwarded();
            } else {
                $quotation->markNotAwarded();
            }
            $this->quotations->save($quotation);
        }
    }
}
