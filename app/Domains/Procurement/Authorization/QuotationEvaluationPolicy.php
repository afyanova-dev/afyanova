<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Authorization;

use App\Domains\Procurement\Application\Command\EvaluateQuotation;
use App\Domains\Procurement\Application\Command\ReviseEvaluation;
use App\Domains\Procurement\Application\Command\SubmitEvaluation;
use App\Domains\Procurement\Domain\Model\QuotationEvaluation;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * QuotationEvaluation aggregate policy (ADR-174 D-H).
 *
 * `create` is the coarse class-level check for `EvaluateQuotation` (the
 * handler's ADR-133 refinement + the "RFQ CLOSED" / "quotation SUBMITTED"
 * checks are the real gate — the `RfqPolicy::create` precedent). `manage` is
 * the per-subject check for `ReviseEvaluation` / `SubmitEvaluation`
 * (`procurement.quotations.evaluate` at the evaluation's facility, ADR-133);
 * the handler additionally requires the actor to be the evaluation's author.
 * Evaluation READS route through `QuotationPolicy::view`
 * (`procurement.quotations.view`) — there is no read method here.
 */
final class QuotationEvaluationPolicy extends BasePolicy
{
    public function create(User $user, ?EvaluateQuotation $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'procurement.quotations.evaluate');
    }

    public function manage(
        User $user,
        QuotationEvaluation $evaluation,
        ReviseEvaluation|SubmitEvaluation|null $command = null,
    ): bool {
        return $this->sameTenant($user, (string) $evaluation->tenant_id)
            && in_array(
                'procurement.quotations.evaluate',
                $this->scope->effectivePermissions($user, (string) $evaluation->facility_id),
                true,
            );
    }
}
