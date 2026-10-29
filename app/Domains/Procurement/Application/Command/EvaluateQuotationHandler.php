<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Exception\EvaluationAlreadyExists;
use App\Domains\Procurement\Domain\Exception\RfqNotOpenForEvaluation;
use App\Domains\Procurement\Domain\Model\Quotation;
use App\Domains\Procurement\Domain\Model\QuotationEvaluation;
use App\Domains\Procurement\Domain\Model\Rfq;
use App\Domains\Procurement\Domain\QuotationEvaluationRepository;
use App\Domains\Procurement\Domain\QuotationRepository;
use App\Domains\Procurement\Domain\RfqRepository;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `EvaluateQuotation` (ADR-174 D-C).
 *
 * Load the quotation → its RFQ must be CLOSED and the quotation SUBMITTED →
 * ADR-133 refinement (the actor holds `procurement.quotations.evaluate` at the
 * RFQ's facility, else `NotFound`) → no existing evaluation for
 * (quotation, actor) → create a DRAFT `pcr_quotation_evaluations` row. One bus
 * transaction; records NO event.
 *
 * `QuotationEvaluationRepository` is the first repository parameter → the bus
 * resolves `QuotationEvaluationPolicy::create` (class-level).
 */
final class EvaluateQuotationHandler
{
    public function __construct(
        private readonly QuotationEvaluationRepository $evaluations,
        private readonly QuotationRepository $quotations,
        private readonly RfqRepository $rfqs,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(EvaluateQuotation $command): void
    {
        $quotation = $this->quotations->getById($command->quotationId);
        $rfq = $this->rfqs->getById((string) $quotation->rfq_id);

        $this->assertActorMayEvaluateAt((string) $rfq->facility_id, $command->evaluationId);

        if ((string) $rfq->status !== Rfq::STATUS_CLOSED) {
            throw RfqNotOpenForEvaluation::status((string) $rfq->id, (string) $rfq->status);
        }
        if ((string) $quotation->status !== Quotation::STATUS_SUBMITTED) {
            throw RfqNotOpenForEvaluation::quotationNotSubmitted((string) $quotation->id, (string) $quotation->status);
        }

        if ($this->evaluations->findByQuotationAndEvaluator((string) $quotation->id, $command->actorId) !== null) {
            throw EvaluationAlreadyExists::forQuotationAndEvaluator((string) $quotation->id, $command->actorId);
        }

        $evaluation = QuotationEvaluation::create(
            id: $command->evaluationId,
            tenantId: $this->context->tenantId(),
            facilityId: (string) $rfq->facility_id,
            rfqId: (string) $rfq->id,
            quotationId: (string) $quotation->id,
            evaluatorUserId: $command->actorId,
            commercialScore: $command->commercialScore,
            technicalScore: $command->technicalScore,
            qualityScore: $command->qualityScore,
            deliveryScore: $command->deliveryScore,
            comments: $command->comments,
        );

        $this->evaluations->save($evaluation);
    }

    private function assertActorMayEvaluateAt(string $facilityId, string $evaluationId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('EvaluateQuotation dispatched without an authenticated user (IC §5).');
        }

        if (! in_array('procurement.quotations.evaluate', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw NotFound::aggregate(QuotationEvaluation::class, $evaluationId);
        }
    }
}
