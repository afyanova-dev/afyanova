<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Reader;

use App\Domains\Procurement\Contracts\Dto\QuotationEvaluationGroup;
use App\Domains\Procurement\Contracts\Dto\QuotationEvaluationSummary;
use App\Domains\Procurement\Contracts\Dto\RfqEvaluationOverview;
use App\Domains\Procurement\Domain\EvaluationSettingsReader;
use App\Domains\Procurement\Domain\Model\Quotation;
use App\Domains\Procurement\Domain\Model\QuotationEvaluation;
use App\Domains\Procurement\Domain\Model\Rfq;
use App\Domains\Procurement\Domain\QuotationEvaluationReader;
use Illuminate\Support\Collection;

final class EloquentQuotationEvaluationReader implements QuotationEvaluationReader
{
    public function __construct(private readonly EvaluationSettingsReader $settings) {}

    public function overviewForRfq(string $rfqId, string $viewerUserId): RfqEvaluationOverview
    {
        $rfqAwarded = Rfq::query()->whereKey($rfqId)->value('status') === Rfq::STATUS_AWARDED;
        $passThreshold = $this->settings->forTenant()->passThreshold;

        $submittedQuotations = Quotation::query()
            ->where('rfq_id', $rfqId)
            ->where('status', Quotation::STATUS_SUBMITTED)
            ->orderBy('quotation_number')
            ->get();

        /** @var Collection<int, QuotationEvaluation> $allEvaluations */
        $allEvaluations = QuotationEvaluation::query()->where('rfq_id', $rfqId)->get();

        $groups = [];

        foreach ($submittedQuotations as $quotation) {
            $forQuotation = $allEvaluations->filter(
                static fn (QuotationEvaluation $e): bool => (string) $e->quotation_id === (string) $quotation->id,
            );

            $submitted = $forQuotation->filter(
                static fn (QuotationEvaluation $e): bool => (string) $e->status === QuotationEvaluation::STATUS_SUBMITTED,
            );

            $viewerHasDraftHere = $forQuotation->contains(
                static fn (QuotationEvaluation $e): bool => (string) $e->evaluator_user_id === $viewerUserId
                    && (string) $e->status === QuotationEvaluation::STATUS_DRAFT,
            );
            $gated = $viewerHasDraftHere && ! $rfqAwarded;

            if ($gated) {
                $visible = $forQuotation->filter(
                    static fn (QuotationEvaluation $e): bool => (string) $e->evaluator_user_id === $viewerUserId,
                );
                $averageOverall = null;
            } else {
                $visible = $submitted;
                $averageOverall = null;
                if ($submitted->isNotEmpty()) {
                    $mean = $submitted->sum(static fn (QuotationEvaluation $e): int => (int) $e->overall_score) / $submitted->count();
                    $averageOverall = (int) round($mean);
                }
            }

            $groups[] = new QuotationEvaluationGroup(
                quotationId: (string) $quotation->id,
                supplierId: (string) $quotation->supplier_id,
                submittedCount: $submitted->count(),
                averageOverall: $averageOverall,
                viewerGated: $gated,
                evaluations: array_values(
                    $visible
                        ->sortBy('evaluator_user_id')
                        ->map(fn (QuotationEvaluation $e): QuotationEvaluationSummary => $this->toSummary($e, $passThreshold))
                        ->all(),
                ),
            );
        }

        return new RfqEvaluationOverview(rfqId: $rfqId, groups: $groups, passThreshold: $passThreshold);
    }

    private function toSummary(QuotationEvaluation $e, ?int $passThreshold): QuotationEvaluationSummary
    {
        return new QuotationEvaluationSummary(
            evaluationId: (string) $e->id,
            evaluatorUserId: (string) $e->evaluator_user_id,
            commercialScore: $e->commercial_score,
            technicalScore: $e->technical_score,
            qualityScore: $e->quality_score,
            deliveryScore: $e->delivery_score,
            overallScore: $e->overall_score,
            status: (string) $e->status,
            submittedAt: $e->submitted_at?->toIso8601String(),
            comments: $e->comments,
            belowPassThreshold: $passThreshold !== null
                && $e->overall_score !== null
                && (int) $e->overall_score < $passThreshold,
        );
    }
}
