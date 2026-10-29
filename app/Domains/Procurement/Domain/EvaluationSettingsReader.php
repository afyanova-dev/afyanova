<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

/**
 * Read-side port for the per-tenant evaluation criteria (IC §6.3; ADR-175 D-B).
 * Read READ-ONLY intra-domain by `SubmitEvaluationHandler`, `AwardRfqHandler`
 * and `EloquentQuotationEvaluationReader` (the `ProcedureDefinitionReader` /
 * ADR-146 precedent) — there is no `#[ProtectedQuery]` surface in Proc.2c.
 */
interface EvaluationSettingsReader
{
    /**
     * The tenant's effective evaluation criteria — the persisted row's values,
     * or {@see EvaluationCriteria::defaults()} when the tenant has never
     * configured anything (⇒ exact Proc.2b behaviour). RLS-scoped to the
     * current tenant context.
     */
    public function forTenant(): EvaluationCriteria;
}
