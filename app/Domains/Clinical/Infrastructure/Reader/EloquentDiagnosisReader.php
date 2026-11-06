<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Reader;

use App\Domains\Clinical\Contracts\Dto\DiagnosisSummary;
use App\Domains\Clinical\Domain\DiagnosisReader;
use App\Domains\Clinical\Domain\Model\Diagnosis;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\DB;

final class EloquentDiagnosisReader implements DiagnosisReader
{
    public function activeCatalog(): array
    {
        return array_values(DB::table('clin_diagnosis_catalog')
            ->where('active', true)
            ->orderByDesc('is_favourite')
            ->orderBy('name')
            ->get(['id', 'code', 'code_system', 'name', 'aliases', 'category'])
            ->map(static fn (object $diagnosis): array => [
                'id' => (string) $diagnosis->id,
                'code' => (string) $diagnosis->code,
                'codeSystem' => (string) $diagnosis->code_system,
                'name' => (string) $diagnosis->name,
                'aliases' => (string) ($diagnosis->aliases ?? ''),
                'category' => (string) $diagnosis->category,
            ])
            ->all());
    }

    public function summary(string $diagnosisId): DiagnosisSummary
    {
        $diagnosis = Diagnosis::query()->whereKey($diagnosisId)->first()
            ?? throw NotFound::aggregate(Diagnosis::class, $diagnosisId);

        return $this->toSummary($diagnosis);
    }

    public function forEncounter(string $encounterId, array $inScopeFacilityIds): array
    {
        if ($inScopeFacilityIds === []) {
            return [];
        }

        $diagnoses = Diagnosis::query()
            ->where('encounter_id', $encounterId)
            ->whereIn('facility_id', $inScopeFacilityIds)
            ->whereNull('superseded_at')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        return array_values($diagnoses
            ->map(fn (Diagnosis $diagnosis): DiagnosisSummary => $this->toSummary($diagnosis))
            ->all());
    }

    private function toSummary(Diagnosis $diagnosis): DiagnosisSummary
    {
        return new DiagnosisSummary(
            diagnosisId: (string) $diagnosis->id,
            patientId: (string) $diagnosis->patient_id,
            encounterId: (string) $diagnosis->encounter_id,
            facilityId: (string) $diagnosis->facility_id,
            authorUserId: (string) $diagnosis->author_user_id,
            authoredContext: $diagnosis->authored_context,
            code: $diagnosis->code,
            codeSystem: $diagnosis->code_system,
            description: (string) $diagnosis->description,
            clinicalStatus: (string) $diagnosis->clinical_status,
            occurredAt: (string) $diagnosis->occurred_at->toISOString(),
            recordedAt: (string) $diagnosis->recorded_at->toISOString(),
            supersedesDiagnosisId: $diagnosis->supersedes_diagnosis_id,
            supersededAt: $diagnosis->superseded_at?->toISOString(),
            supersededByDiagnosisId: $diagnosis->superseded_by_diagnosis_id,
            correctionReason: $diagnosis->reason,
        );
    }
}
