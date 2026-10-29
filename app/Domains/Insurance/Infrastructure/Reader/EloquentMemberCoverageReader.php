<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Reader;

use App\Domains\Insurance\Contracts\Dto\MemberCoverageSummary;
use App\Domains\Insurance\Domain\Exception\MemberCoverageNotFound;
use App\Domains\Insurance\Domain\MemberCoverageReader;
use App\Domains\Insurance\Domain\Model\MemberCoverage;
use App\Domains\Insurance\Domain\ValueObject\CoveragePriority;
use App\Domains\Insurance\Domain\ValueObject\MemberCoverageStatus;

/**
 * Eloquent implementation of {@see MemberCoverageReader} (IC §6.2; ADR-199 D-C).
 */
final class EloquentMemberCoverageReader implements MemberCoverageReader
{
    public function summary(string $memberCoverageId): MemberCoverageSummary
    {
        $coverage = MemberCoverage::query()->whereKey($memberCoverageId)->first()
            ?? throw MemberCoverageNotFound::id($memberCoverageId);

        return $this->toSummary($coverage);
    }

    public function forPatient(string $patientId): array
    {
        $rows = MemberCoverage::query()->where('patient_id', $patientId)->get();

        $sorted = $rows->sort(function (MemberCoverage $a, MemberCoverage $b): int {
            $keyA = [
                $a->status === MemberCoverageStatus::ACTIVE ? 0 : 1,
                CoveragePriority::rank((string) $a->priority),
                $a->recorded_at->getTimestamp(),
            ];
            $keyB = [
                $b->status === MemberCoverageStatus::ACTIVE ? 0 : 1,
                CoveragePriority::rank((string) $b->priority),
                $b->recorded_at->getTimestamp(),
            ];

            return $keyA <=> $keyB;
        })->values();

        return array_values($sorted->map(fn (MemberCoverage $c): MemberCoverageSummary => $this->toSummary($c))->all());
    }

    public function search(?string $planId, ?string $payerId, ?string $status): array
    {
        $query = MemberCoverage::query();

        if ($planId !== null) {
            $query->where('plan_id', $planId);
        }
        if ($payerId !== null) {
            $query->where('payer_id', $payerId);
        }
        if ($status !== null) {
            $query->where('status', strtoupper(trim($status)));
        }

        return array_values(
            $query->orderByDesc('recorded_at')->orderBy('id')->get()
                ->map(fn (MemberCoverage $c): MemberCoverageSummary => $this->toSummary($c))
                ->all(),
        );
    }

    private function toSummary(MemberCoverage $c): MemberCoverageSummary
    {
        return new MemberCoverageSummary(
            memberCoverageId: (string) $c->id,
            patientId: (string) $c->patient_id,
            planId: (string) $c->plan_id,
            payerId: (string) $c->payer_id,
            memberNumber: $c->member_number,
            policyNumber: $c->policy_number,
            cardNumber: $c->card_number,
            relationship: (string) $c->relationship,
            principalMemberCoverageId: $c->principal_member_coverage_id,
            priority: (string) $c->priority,
            effectiveFrom: $c->effective_from->toDateString(),
            effectiveTo: $c->effective_to?->toDateString(),
            status: (string) $c->status,
            terminatedAt: $c->terminated_at?->toIso8601String(),
            terminationReason: $c->termination_reason,
            recordedAt: $c->recorded_at->toIso8601String(),
        );
    }
}
