<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Repository;

use App\Domains\Procurement\Domain\Model\QuotationEvaluation;
use App\Domains\Procurement\Domain\QuotationEvaluationRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

final class EloquentQuotationEvaluationRepository extends EloquentRepository implements QuotationEvaluationRepository
{
    protected function model(): string
    {
        return QuotationEvaluation::class;
    }

    public function getById(string $id): QuotationEvaluation
    {
        return QuotationEvaluation::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(QuotationEvaluation::class, $id);
    }

    public function getByIdForUpdate(string $id): QuotationEvaluation
    {
        return QuotationEvaluation::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(QuotationEvaluation::class, $id);
    }

    public function findByQuotationAndEvaluator(string $quotationId, string $evaluatorUserId): ?QuotationEvaluation
    {
        return QuotationEvaluation::query()
            ->where('quotation_id', $quotationId)
            ->where('evaluator_user_id', $evaluatorUserId)
            ->first();
    }

    public function evaluatedQuotationIds(string $rfqId): array
    {
        return array_values(
            QuotationEvaluation::query()
                ->where('rfq_id', $rfqId)
                ->where('status', QuotationEvaluation::STATUS_SUBMITTED)
                ->distinct()
                ->pluck('quotation_id')
                ->map(static fn ($id): string => (string) $id)
                ->all()
        );
    }
}
