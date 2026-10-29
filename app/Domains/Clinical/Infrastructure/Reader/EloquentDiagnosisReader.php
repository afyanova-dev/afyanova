<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Reader;

use App\Domains\Clinical\Contracts\Dto\DiagnosisSummary;
use App\Domains\Clinical\Domain\DiagnosisReader;
use App\Domains\Clinical\Domain\Model\Diagnosis;
use App\Shared\Domain\NotFound;

final class EloquentDiagnosisReader implements DiagnosisReader
{
    public function summary(string $diagnosisId): DiagnosisSummary
    {
        $diagnosis = Diagnosis::query()->whereKey($diagnosisId)->first()
            ?? throw NotFound::aggregate(Diagnosis::class, $diagnosisId);

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
