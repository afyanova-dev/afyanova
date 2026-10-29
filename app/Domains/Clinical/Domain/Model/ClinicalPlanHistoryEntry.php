<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Model;

use App\Domains\Clinical\Database\Factories\ClinicalPlanHistoryEntryFactory;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * One immutable PRE-CHANGE snapshot of a {@see ClinicalPlan}'s state, taken
 * the instant before an `UpdateClinicalPlan` applies its change (ADR-143 D-E / D-T).
 *
 * Append-only at database (`Rls::enableAppendOnly`); version stays 0.
 *
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 */
final class ClinicalPlanHistoryEntry extends AggregateRoot
{
    /** @use HasFactory<ClinicalPlanHistoryEntryFactory> */
    use HasFactory;

    protected $table = 'clin_plan_history';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'plan_id', 'patient_id', 'encounter_id',
        'change_type',
        'plan_type', 'clinical_intent', 'goals', 'planned_actions',
        'instructions', 'follow_up', 'status', 'closed_reason',
        'indication_diagnosis_id', 'indication_problem_id',
        'reason', 'author_user_id', 'authored_context',
        'occurred_at', 'recorded_at',
    ];

    protected static function newFactory(): ClinicalPlanHistoryEntryFactory
    {
        return ClinicalPlanHistoryEntryFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public static function fromPreChangeState(
        ClinicalPlan $plan,
        string $changeType,
        ?string $reason,
        string $authorUserId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $plan->tenant_id,
            'facility_id' => (string) $plan->facility_id,
            'plan_id' => (string) $plan->id,
            'patient_id' => (string) $plan->patient_id,
            'encounter_id' => (string) $plan->encounter_id,
            'change_type' => $changeType,
            'plan_type' => (string) $plan->plan_type,
            'clinical_intent' => (string) $plan->clinical_intent,
            'goals' => $plan->goals,
            'planned_actions' => $plan->planned_actions,
            'instructions' => $plan->instructions,
            'follow_up' => $plan->follow_up,
            'status' => (string) $plan->status,
            'closed_reason' => $plan->closed_reason,
            'indication_diagnosis_id' => $plan->indication_diagnosis_id,
            'indication_problem_id' => $plan->indication_problem_id,
            'reason' => $reason,
            'author_user_id' => $authorUserId,
            'authored_context' => ClinicalPlan::AUTHORED_CONTEXT,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }
}
