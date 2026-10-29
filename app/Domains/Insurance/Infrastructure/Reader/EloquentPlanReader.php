<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Reader;

use App\Domains\Insurance\Contracts\Dto\PayerPlanSummary;
use App\Domains\Insurance\Domain\Exception\PayerPlanNotFound;
use App\Domains\Insurance\Domain\Model\Plan;
use App\Domains\Insurance\Domain\PlanReader;

/**
 * Eloquent implementation of {@see PlanReader} (IC §6.2; ADR-198 D-I).
 */
final class EloquentPlanReader implements PlanReader
{
    public function summary(string $planId): PayerPlanSummary
    {
        $plan = Plan::query()->whereKey($planId)->first()
            ?? throw PayerPlanNotFound::id($planId);

        return $this->toSummary($plan);
    }

    public function search(?string $payerId, ?string $status): array
    {
        $query = Plan::query();

        if ($payerId !== null) {
            $query->where('payer_id', $payerId);
        }
        if ($status !== null) {
            $query->where('status', strtoupper(trim($status)));
        }

        return array_values(
            $query->orderByDesc('recorded_at')->orderBy('id')->get()
                ->map(fn (Plan $p): PayerPlanSummary => $this->toSummary($p))
                ->all(),
        );
    }

    private function toSummary(Plan $plan): PayerPlanSummary
    {
        return new PayerPlanSummary(
            planId: (string) $plan->id,
            payerId: (string) $plan->payer_id,
            code: (string) $plan->code,
            name: (string) $plan->name,
            status: (string) $plan->status,
            statusReason: $plan->status_reason,
            effectiveFrom: $plan->effective_from?->toDateString(),
            effectiveTo: $plan->effective_to?->toDateString(),
            recordedAt: $plan->recorded_at->toIso8601String(),
        );
    }
}
