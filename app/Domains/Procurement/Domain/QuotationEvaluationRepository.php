<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use App\Domains\Procurement\Domain\Model\QuotationEvaluation;
use App\Shared\Domain\Repository;

/** Write-side port for the QuotationEvaluation aggregate (IC §9; ADR-174 D-B). */
interface QuotationEvaluationRepository extends Repository
{
    public function getById(string $id): QuotationEvaluation;

    public function getByIdForUpdate(string $id): QuotationEvaluation;

    /** The evaluation for this (quotation, evaluator), or null (RLS-scoped). */
    public function findByQuotationAndEvaluator(string $quotationId, string $evaluatorUserId): ?QuotationEvaluation;

    /**
     * The distinct quotation ids on an RFQ that carry ≥ 1 SUBMITTED evaluation
     * — the `AwardRfqHandler` soft-gate input (ADR-174 D-F).
     *
     * @return list<string>
     */
    public function evaluatedQuotationIds(string $rfqId): array;
}
