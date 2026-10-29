<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Reader;

use App\Domains\Clinical\Contracts\Dto\ProblemSummary;
use App\Domains\Clinical\Domain\Model\Problem;
use App\Domains\Clinical\Domain\ProblemReader;
use App\Shared\Domain\NotFound;

final class EloquentProblemReader implements ProblemReader
{
    public function summary(string $problemId): ProblemSummary
    {
        $problem = Problem::query()->whereKey($problemId)->first()
            ?? throw NotFound::aggregate(Problem::class, $problemId);

        return $this->toSummary($problem);
    }

    public function forPatient(string $patientId, ?string $status, array $inScopeFacilityIds): array
    {
        if ($inScopeFacilityIds === []) {
            return [];
        }

        $query = Problem::query()
            ->where('patient_id', $patientId)
            ->whereIn('facility_id', $inScopeFacilityIds);

        if ($status !== null) {
            $query->where('status', $status);
        }

        $summaries = $query
            ->orderByDesc('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Problem $problem): ProblemSummary => $this->toSummary($problem))
            ->all();

        return array_values($summaries);
    }

    private function toSummary(Problem $problem): ProblemSummary
    {
        return new ProblemSummary(
            problemId: (string) $problem->id,
            patientId: (string) $problem->patient_id,
            encounterId: (string) $problem->encounter_id,
            facilityId: (string) $problem->facility_id,
            authorUserId: (string) $problem->author_user_id,
            authoredContext: $problem->authored_context,
            code: $problem->code,
            codeSystem: $problem->code_system,
            description: (string) $problem->description,
            status: (string) $problem->status,
            onsetDate: $problem->onset_date?->toDateString(),
            resolvedAt: $problem->resolved_at?->toISOString(),
            sourceDiagnosisId: $problem->source_diagnosis_id,
            occurredAt: (string) $problem->occurred_at->toISOString(),
            recordedAt: (string) $problem->recorded_at->toISOString(),
        );
    }
}
