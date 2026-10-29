<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Model;

use App\Domains\Patient\Database\Factories\PatientIdentityHistoryFactory;
use App\Domains\Patient\Domain\Enum\MergeHistoryStatus;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Exception\PatientMergeRejected;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * The immutable identity-mapping + merge record for one patient merge
 * (10-patient-domain.md §24, §144, §152, §159; ADR-118, ADR-128).
 *
 * Its OWN small tenant-scoped aggregate (ADR-128 M-4/M-5) — same pattern as
 * PatientIdentifier / PatientRegistration; `EloquentRepository` and
 * `EloquentPatientRepository` are not touched. The `PatientMerged` /
 * `PatientUnmerged` domain events are recorded by the Patient aggregate, not
 * here — this record carries no events.
 *
 * The row is written once (ACTIVE), transitioned to REVERSED by
 * `RequestUnmerge`, and NEVER deleted (Invariants 15, 27, 28).
 *
 * @domain      Patient
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    not applicable — identity-level, tenant-scoped (ADR-114).
 *
 * @property MergeHistoryStatus $status
 * @property PatientStatus $non_surviving_prior_status
 * @property CarbonImmutable $merged_at
 * @property CarbonImmutable|null $unmerged_at
 */
final class PatientIdentityHistory extends AggregateRoot
{
    /** @use HasFactory<PatientIdentityHistoryFactory> */
    use HasFactory;

    protected $table = 'pat_patient_identity_history';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'merge_id',
        'surviving_patient_id', 'non_surviving_patient_id',
        'non_surviving_patient_number', 'non_surviving_prior_status',
        'reason', 'evidence', 'merged_by_user_id', 'merged_at',
        'status', 'unmerged_by_user_id', 'unmerged_at',
    ];

    protected static function newFactory(): PatientIdentityHistoryFactory
    {
        return PatientIdentityHistoryFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MergeHistoryStatus::class,
            'non_surviving_prior_status' => PatientStatus::class,
            'merged_at' => 'immutable_datetime',
            'unmerged_at' => 'immutable_datetime',
        ];
    }

    /**
     * Record a merge (ADR-128). The caller (MergePatientsHandler) has already
     * validated both patients, locked both rows, and transitioned the
     * non-surviving Patient to MERGED.
     */
    public static function record(
        string $id,
        string $tenantId,
        string $mergeId,
        string $survivingPatientId,
        string $nonSurvivingPatientId,
        string $nonSurvivingPatientNumber,
        PatientStatus $nonSurvivingPriorStatus,
        string $reason,
        string $evidence,
        string $mergedByUserId,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'merge_id' => $mergeId,
            'surviving_patient_id' => $survivingPatientId,
            'non_surviving_patient_id' => $nonSurvivingPatientId,
            'non_surviving_patient_number' => $nonSurvivingPatientNumber,
            'non_surviving_prior_status' => $nonSurvivingPriorStatus,
            'reason' => $reason,
            'evidence' => $evidence,
            'merged_by_user_id' => $mergedByUserId,
            'merged_at' => CarbonImmutable::now(),
            'status' => MergeHistoryStatus::Active,
        ]);
    }

    /**
     * ACTIVE -> REVERSED (ADR-128 M-3). The row is retained as immutable
     * evidence; only this one governed transition and the reversal stamps
     * change.
     */
    public function reverse(string $unmergedByUserId): void
    {
        if ($this->status !== MergeHistoryStatus::Active) {
            throw PatientMergeRejected::notCurrentlyMerged((string) $this->non_surviving_patient_id);
        }

        $this->status = MergeHistoryStatus::Reversed;
        $this->unmerged_by_user_id = $unmergedByUserId;
        $this->unmerged_at = CarbonImmutable::now();
    }
}
