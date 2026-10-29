<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Contracts\Dto\PayerPlanSummary;

/**
 * Read-side port for the payer-plan master (IC §6.2; ADR-198 D-I). Returns DTOs
 * only.
 */
interface PlanReader
{
    /** Throws `App\Domains\Insurance\Domain\Exception\PayerPlanNotFound` if absent (RLS). */
    public function summary(string $planId): PayerPlanSummary;

    /**
     * @return list<PayerPlanSummary> newest first
     */
    public function search(?string $payerId, ?string $status): array;
}
